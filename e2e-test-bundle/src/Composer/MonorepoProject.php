<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Composer;

use Contao\E2eTestBundle\Exception\E2eTestException;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Symfony\Component\Filesystem\Path;

final readonly class MonorepoProject
{
    private function __construct(
        public string $directory,
        public string $version,
    ) {
    }

    public static function discover(string $directory): self
    {
        $directory = Path::canonicalize($directory);
        $composer = self::readComposerFile(Path::join($directory, 'composer.json'));

        return new self($directory, self::resolveVersion($composer));
    }

    public function configureComposer(ComposerConfig $composer, string ...$packageDirectories): ComposerConfig
    {
        foreach ($packageDirectories as $packageDirectory) {
            $path = Path::join($this->directory, $packageDirectory);
            $package = self::readComposerFile(Path::join($path, 'composer.json'));
            $name = $package['name'] ?? null;

            if (!\is_string($name)) {
                throw new E2eTestException(\sprintf('The Composer package in "%s" has no name.', $path));
            }

            $composer = $composer->withPathPackage($name, $path, $this->version);
        }

        return $composer;
    }

    /**
     * @param array<string, mixed> $composer
     */
    private static function resolveVersion(array $composer): string
    {
        if (isset($composer['version']) && \is_string($composer['version'])) {
            return $composer['version'];
        }

        $alias = $composer['extra']['branch-alias']['dev-main'] ?? null;

        if (!\is_string($alias) || !str_ends_with($alias, '-dev')) {
            // Path packages only need a shared concrete development version.
            return 'dev-main';
        }

        $prefix = substr($alias, 0, -4);

        return str_ends_with($prefix, '.x') ? $alias : $prefix.'.x-dev';
    }

    /**
     * @return array<string, mixed>
     */
    private static function readComposerFile(string $path): array
    {
        if (!is_file($path)) {
            throw new E2eTestException(\sprintf('The Composer file "%s" does not exist.', $path));
        }

        try {
            $composer = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new E2eTestException(\sprintf('The Composer file "%s" is invalid.', $path), 0, $exception);
        }

        if (!\is_array($composer)) {
            throw new E2eTestException(\sprintf('The Composer file "%s" must contain an object.', $path));
        }

        return $composer;
    }
}
