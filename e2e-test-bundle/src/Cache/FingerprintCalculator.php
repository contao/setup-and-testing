<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Cache;

use Contao\E2eTestBundle\ManagedEdition\ManagedEditionConfig;
use Contao\InstallationRecipe\File\FileMapping;

final readonly class FingerprintCalculator
{
    public function __construct(private SourceFingerprintInterface $sourceFingerprint = new ProcessCachedSourceFingerprint())
    {
    }

    public function calculate(ManagedEditionConfig $config): FingerprintSet
    {
        $recipe = $config->recipe;
        $projectDirectory = $config->environment->cache->projectDirectory;
        $composer = $config->environment->composer;
        $dependency = $this->hash([
            $recipe->composer->toArray($projectDirectory),
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

        $application = $this->hash([
            $dependency,
            $sources,
            $this->hashFiles(array_map(static fn ($fragment) => $fragment->path, $recipe->assets->configFragments)),
            $this->hashMappings($recipe->assets->fileMappings),
        ]);
        $data = $this->hash([$application, $this->hashFiles($recipe->fixtures->files)]);

        return new FingerprintSet($dependency, $application, $data);
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
