<?php

declare(strict_types=1);

namespace Contao\E2eTestBundle\Installation;

use Contao\E2eTestBundle\Composer\ComposerInstaller;
use Contao\E2eTestBundle\ManagedEdition\ManagedEditionConfig;
use Contao\E2eTestBundle\Process\ContaoConsole;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final readonly class InstallationBuilder
{
    public function __construct(
        private ComposerInstaller $composerInstaller,
        private ApplicationPreparer $applicationPreparer,
        private ContaoConsole $contaoConsole,
    ) {
    }

    public function prepare(ManagedEditionConfig $config, PreparedInstallation $installation): void
    {
        $directory = $installation->directory();
        $manifestPath = Path::join($directory, '.contao-e2e-manifest.json');
        $manifest = InstallationManifest::read($manifestPath);

        if (!is_dir(Path::join($directory, 'vendor')) || $manifest?->dependency !== $installation->fingerprints->dependency) {
            $this->coldInstall($config, $installation, $manifestPath);
            $manifest = InstallationManifest::read($manifestPath);
        }

        $applicationChanged = $manifest?->application !== $installation->fingerprints->application;
        $installation->database->create();

        if ($applicationChanged) {
            $this->prepareApplication($config, $installation, $manifest);
        }

        if ($applicationChanged || !$installation->database->hasSchema()) {
            $this->contaoConsole->migrate($directory, $installation->database->applicationUrl());
        }

        $installation->database->reset($config->recipe->fixtures);
    }

    private function coldInstall(ManagedEditionConfig $config, PreparedInstallation $installation, string $manifestPath): void
    {
        $directory = $installation->directory();
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

    private function prepareApplication(ManagedEditionConfig $config, PreparedInstallation $installation, InstallationManifest|null $manifest): void
    {
        $directory = $installation->directory();
        $this->applicationPreparer->prepare($config, $directory, $manifest);
        $this->contaoConsole->setup($directory, $installation->database->applicationUrl());
        $mappedTargets = array_map(static fn ($mapping) => $mapping->target, $config->recipe->assets->fileMappings);
        new InstallationManifest(
            $installation->fingerprints->dependency,
            $installation->fingerprints->application,
            $mappedTargets,
        )->write(Path::join($directory, '.contao-e2e-manifest.json'));
    }
}
