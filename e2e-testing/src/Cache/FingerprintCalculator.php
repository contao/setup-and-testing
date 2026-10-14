<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Cache;

use Contao\E2eTesting\Composer\ComposerPathRepositories;
use Contao\E2eTesting\Composer\PathPackageMetadata;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\InstallationRecipe\File\FileMapping;
use Symfony\Component\Filesystem\Path;

final readonly class FingerprintCalculator
{
    public function __construct(private SourceFingerprintInterface $sourceFingerprint)
    {
    }

    public function calculate(ManagedEditionConfig $config): FingerprintSet
    {
        $recipe = $config->recipe;
        $packages = (new ComposerPathRepositories())->metadata($recipe->composer, $config->environment->cache->projectDirectory);
        $sources = $this->hashSources($packages->directories);
        $dependency = $this->dependencyFingerprint($config, $sources, $packages);

        $application = $this->hash([
            $dependency,
            $config->appEnvironment,
            $config->environmentVariables(),
            $sources,
            $this->hashFiles(array_map(static fn ($fragment) => $fragment->path, $recipe->assets->configFragments)),
            $this->hashMappings($recipe->assets->fileMappings),
        ]);
        $data = $this->hash([$application, $this->hashFiles($recipe->fixtures->files)]);

        return new FingerprintSet($dependency, $application, $data);
    }

    /**
     * @param array<string, string> $sources
     */
    private function dependencyFingerprint(ManagedEditionConfig $config, array $sources, PathPackageMetadata $packages): string
    {
        $composer = $config->recipe->composer;
        $runConfig = $config->environment->composer;
        $copiedSources = array_intersect_key($sources, array_flip($packages->copiedDirectories));

        return $this->hash([
            $composer->toArray($config->environment->cache->projectDirectory),
            $this->hashFiles(array_map(
                static fn ($directory) => Path::join($directory, 'composer.json'),
                array_keys($sources),
            )),
            $packages->versions,
            // Only forced symlinks guarantee that vendor packages follow source changes.
            $copiedSources,
            \PHP_VERSION_ID,
            PHP_OS_FAMILY,
            $runConfig->executable,
            $runConfig->preferLowest,
            $runConfig->preferStable,
        ]);
    }

    /**
     * @param list<string> $directories
     *
     * @return array<string, string>
     */
    private function hashSources(array $directories): array
    {
        $sources = [];

        foreach ($directories as $directory) {
            $sources[$directory] = $this->sourceFingerprint->calculate($directory);
        }

        return $sources;
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', serialize($value));
    }

    /**
     * @param list<string> $files
     *
     * @return array<string, string|false>
     */
    private function hashFiles(array $files): array
    {
        $hashes = [];

        foreach ($files as $file) {
            $hashes[$file] = hash_file('sha256', $file);
        }

        return $hashes;
    }

    /**
     * @param list<FileMapping> $mappings
     *
     * @return array<string, array{bool, string}>
     */
    private function hashMappings(array $mappings): array
    {
        $hashes = [];

        foreach ($mappings as $mapping) {
            $hashes[$mapping->target] = [$mapping->overwrite, $this->sourceFingerprint->calculate($mapping->source)];
        }

        return $hashes;
    }
}
