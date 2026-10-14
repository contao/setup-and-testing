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
     */
    private function __construct(
        private array $config,
        private readonly string $directory,
    ) {
    }

    public static function managedEdition(string $constraint): self
    {
        return new self(
            [
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
            ],
            (string) getcwd(),
        );
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

        return (new self([], Path::makeAbsolute(\dirname($path), (string) getcwd())))->withOverrides($config);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function withOverrides(array $overrides): self
    {
        $clone = clone $this;
        $clone->config = $this->mergeConfig($this->config, $overrides);
        if (isset($clone->config['repositories']) && \is_array($clone->config['repositories'])) {
            $clone->config['repositories'] = (new PathRepositoryPaths())->resolve($clone->config['repositories'], $this->directory);
        }

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $targetDirectory): array
    {
        $config = $this->config;

        if (isset($config['repositories']) && \is_array($config['repositories'])) {
            $config['repositories'] = (new PathRepositoryPaths())->relativize($config['repositories'], $targetDirectory);
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
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overrides
     *
     * @return array<array-key, mixed>
     */
    private function mergeConfig(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (\is_array($value) && !array_is_list($value) && isset($base[$key]) && \is_array($base[$key]) && !array_is_list($base[$key])) {
                $value = $this->mergeConfig($base[$key], $value);
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
