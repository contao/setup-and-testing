<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Installation;

use Contao\E2eTesting\Composer\ComposerInstaller;
use Contao\E2eTesting\Database\InstallationDatabaseInterface;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\E2eTesting\Process\ContaoConsole;
use Contao\InstallationRecipe\Cache\InMemoryCache;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final readonly class InstallationBuilder
{
    public function __construct(
        private ComposerInstaller $composerInstaller,
        private ApplicationPreparer $applicationPreparer,
        private ContaoConsole $contaoConsole,
        private InMemoryCache $cache,
    ) {
    }

    public function prepare(ManagedEditionConfig $config, InstallationWorkspace $installation, InstallationDatabaseInterface $database): void
    {
        $directory = $installation->directory;
        $manifestPath = Path::join($directory, '.contao-e2e-manifest.json');
        $manifest = InstallationManifest::read($manifestPath);

        if (!is_dir(Path::join($directory, 'vendor')) || $manifest?->dependency !== $installation->fingerprints->dependency) {
            $this->coldInstall($config, $installation, $manifestPath);
            $manifest = InstallationManifest::read($manifestPath);
        }

        $applicationChanged = $manifest?->application !== $installation->fingerprints->application;
        $database->create();

        if ($applicationChanged) {
            $this->applicationPreparer->prepare($config, $directory, $manifest);
            // Track copied targets before autoload generation or setup can fail.
            $this->writeApplicationManifest($config, $installation, complete: false);
            $this->composerInstaller->refreshAutoload($config, $directory);
            $this->contaoConsole->setup($directory, $database->applicationUrl());
        }

        if ($applicationChanged || !$database->hasSchema()) {
            // A failed migration must remain pending even if it created part of the schema.
            $this->writeApplicationManifest($config, $installation, complete: false);
            $this->cache->clear();
            $this->contaoConsole->migrate($directory, $database->applicationUrl());
            $this->writeApplicationManifest($config, $installation);
        }

        $database->reset($config->recipe->fixtures);
    }

    private function coldInstall(ManagedEditionConfig $config, InstallationWorkspace $installation, string $manifestPath): void
    {
        $directory = $installation->directory;
        $buildDirectory = $directory.'.building-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->remove($buildDirectory);
        $filesystem->mkdir($buildDirectory);

        try {
            // Composer plugins may load recipe bootstrap classes during autoload generation.
            $this->applicationPreparer->prepare($config, $buildDirectory, null);
            $this->composerInstaller->install($config, $buildDirectory);
            $filesystem->remove($directory);
            $filesystem->rename($buildDirectory, $directory);
        } catch (\Throwable $exception) {
            $filesystem->remove($buildDirectory);

            throw $exception;
        }

        $mappedTargets = array_map(static fn ($mapping) => $mapping->target, $config->recipe->assets->fileMappings);
        (new InstallationManifest($installation->fingerprints->dependency, mappedTargets: $mappedTargets))->write($manifestPath);
    }

    private function writeApplicationManifest(ManagedEditionConfig $config, InstallationWorkspace $installation, bool $complete = true): void
    {
        $directory = $installation->directory;
        $mappedTargets = array_map(static fn ($mapping) => $mapping->target, $config->recipe->assets->fileMappings);
        (new InstallationManifest(
            $installation->fingerprints->dependency,
            $complete ? $installation->fingerprints->application : null,
            $mappedTargets,
        ))->write(Path::join($directory, '.contao-e2e-manifest.json'));
    }
}
