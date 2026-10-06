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
            $this->contaoConsole->setup($directory, $database->applicationUrl());
            $this->writeApplicationManifest($config, $installation);
        }

        if ($applicationChanged || !$database->hasSchema()) {
            $this->cache->clear();
            $this->contaoConsole->migrate($directory, $database->applicationUrl());
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
            $this->composerInstaller->install($config, $buildDirectory);
            $filesystem->remove($directory);
            $filesystem->rename($buildDirectory, $directory);
        } catch (\Throwable $exception) {
            $filesystem->remove($buildDirectory);

            throw $exception;
        }

        (new InstallationManifest($installation->fingerprints->dependency))->write($manifestPath);
    }

    private function writeApplicationManifest(ManagedEditionConfig $config, InstallationWorkspace $installation): void
    {
        $directory = $installation->directory;
        $mappedTargets = array_map(static fn ($mapping) => $mapping->target, $config->recipe->assets->fileMappings);
        (new InstallationManifest(
            $installation->fingerprints->dependency,
            $installation->fingerprints->application,
            $mappedTargets,
        ))->write(Path::join($directory, '.contao-e2e-manifest.json'));
    }
}
