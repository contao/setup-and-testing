<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\Composer;

use Contao\InstallationRecipe\Exception\InvalidRecipeException;
use Symfony\Component\Filesystem\Path;

final class ComposerConfig
{
    /**
     * @param array<string, mixed> $config
     * @param list<PathPackage>    $pathPackages
     */
    private function __construct(
        private readonly array $config,
        private readonly array $pathPackages = [],
    ) {
    }

    public static function managedEdition(string $constraint): self
    {
        return new self([
            'name' => 'contao/e2e-managed-edition',
            'description' => 'Temporary Contao Managed Edition for end-to-end tests',
            'type' => 'project',
            'license' => 'proprietary',
            'require' => ['contao/manager-bundle' => $constraint],
            'scripts' => [
                'post-install-cmd' => ['@php vendor/bin/contao-setup'],
                'post-update-cmd' => ['@php vendor/bin/contao-setup'],
            ],
            'config' => [
                'allow-plugins' => [
                    'contao-components/installer' => true,
                    'contao/manager-plugin' => true,
                    'php-http/discovery' => false,
                ],
            ],
            'extra' => [
                'contao-component-dir' => 'assets',
            ],
        ]);
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new InvalidRecipeException(\sprintf('The Composer file "%s" does not exist.', $path));
        }

        try {
            $config = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidRecipeException(\sprintf('The Composer file "%s" is invalid: %s', $path, $exception->getMessage()), 0, $exception);
        }

        if (!\is_array($config)) {
            throw new InvalidRecipeException(\sprintf('The Composer file "%s" must contain a JSON object.', $path));
        }

        return new self($config);
    }

    public function require(string $package, string $constraint): self
    {
        $config = $this->config;
        $config['require'][$package] = $constraint;

        return new self($config, $this->pathPackages);
    }

    public function withPathPackage(string $package, string $path, string $version): self
    {
        $pathPackages = $this->pathPackages;
        $pathPackages[] = new PathPackage($package, Path::canonicalize($path), $version);

        return $this->require($package, $version)->withPathPackages($pathPackages);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $targetDirectory): array
    {
        $config = $this->config;

        foreach ($this->pathPackages as $pathPackage) {
            $config['repositories'][] = $pathPackage->toComposerRepository($targetDirectory);
        }

        if (isset($config['require']) && \is_array($config['require'])) {
            ksort($config['require']);
        }

        return $config;
    }

    public function toJson(string $targetDirectory): string
    {
        return json_encode($this->toArray($targetDirectory), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @return list<PathPackage>
     */
    public function pathPackages(): array
    {
        return $this->pathPackages;
    }

    /**
     * @param list<PathPackage> $pathPackages
     */
    private function withPathPackages(array $pathPackages): self
    {
        return new self($this->config, $pathPackages);
    }
}
