<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\Configuration;

use Contao\InstallationRecipe\Exception\InvalidRecipeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class ConfigMerger
{
    public function __construct(private Filesystem $filesystem = new Filesystem())
    {
    }

    /**
     * @param list<ConfigFragment> $fragments
     */
    public function merge(array $fragments, string $targetDirectory): bool
    {
        if ([] === $fragments) {
            return false;
        }

        $configFile = Path::join($targetDirectory, 'config/config.yaml');
        $config = is_file($configFile) ? $this->parse($configFile) : [];
        $original = $config;

        foreach ($fragments as $fragment) {
            $config = $this->mergeMappings($config, $this->parse($fragment->path));
        }

        if ($config !== $original || !is_file($configFile)) {
            $this->filesystem->dumpFile($configFile, Yaml::dump($config, 10, 4));
        }

        return $config !== $original;
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $path): array
    {
        try {
            $config = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new InvalidRecipeException(\sprintf('The configuration file "%s" is invalid: %s', $path, $exception->getMessage()), 0, $exception);
        }

        if (null === $config) {
            return [];
        }

        if (!\is_array($config)) {
            throw new InvalidRecipeException(\sprintf('The configuration file "%s" must contain a YAML mapping.', $path));
        }

        /** @var array<string, mixed> $config */
        return $config;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function mergeMappings(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (isset($base[$key]) && \is_array($base[$key]) && \is_array($value) && !array_is_list($value)) {
                $base[$key] = $this->mergeMappings($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
