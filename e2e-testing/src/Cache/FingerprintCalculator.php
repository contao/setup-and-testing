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

use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\InstallationRecipe\File\FileMapping;
use Symfony\Component\Filesystem\Path;

final readonly class FingerprintCalculator
{
    public function __construct(
        private SourceFingerprintInterface $sourceFingerprint = new ProcessCachedSourceFingerprint(),
        private ValueFingerprint $valueFingerprint = new ValueFingerprint(),
    ) {
    }

    public function calculate(ManagedEditionConfig $config): FingerprintSet
    {
        $recipe = $config->recipe;
        $projectDirectory = $config->environment->cache->projectDirectory;
        $composer = $config->environment->composer;
        $dependency = $this->valueFingerprint->calculate([
            $recipe->composer->toArray($projectDirectory),
            $this->hashFiles(array_map(
                static fn ($package) => Path::join($package->path, 'composer.json'),
                $recipe->composer->pathPackages(),
            )),
            \PHP_VERSION_ID,
            PHP_OS_FAMILY,
            $composer->executable,
            $composer->preferLowest,
            $composer->preferStable,
        ]);
        $sources = [];

        foreach ($recipe->composer->pathPackages() as $package) {
            $sources[$package->package] = $this->sourceFingerprint->calculate($package->path);
        }

        $application = $this->valueFingerprint->calculate([
            $dependency,
            $config->appEnvironment,
            $sources,
            $this->hashFiles(array_map(static fn ($fragment) => $fragment->path, $recipe->assets->configFragments)),
            $this->hashMappings($recipe->assets->fileMappings),
        ]);
        $data = $this->valueFingerprint->calculate([$application, $this->hashFiles($recipe->fixtures->files)]);

        return new FingerprintSet($dependency, $application, $data);
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
