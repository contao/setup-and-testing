<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Composer;

use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\E2eTesting\Process\ProcessRunnerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final readonly class ComposerInstaller
{
    public function __construct(
        private ProcessRunnerInterface $processRunner,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function install(ManagedEditionConfig $config, string $directory): void
    {
        $this->filesystem->dumpFile(Path::join($directory, 'composer.json'), $config->recipe->composer->toJson($directory));
        $runConfig = $config->environment->composer;
        $command = ['update', '--no-progress'];

        if ($runConfig->preferLowest) {
            $command[] = '--prefer-lowest';
        }

        if ($runConfig->preferStable) {
            $command[] = '--prefer-stable';
        }

        $this->run($config, $directory, $command);
    }

    public function refreshAutoload(ManagedEditionConfig $config, string $directory): void
    {
        $this->run($config, $directory, ['dump-autoload']);
    }

    /**
     * @param list<string> $command
     */
    private function run(ManagedEditionConfig $config, string $directory, array $command): void
    {
        $this->processRunner->run(
            [$config->environment->composer->executable, ...$command, '--no-interaction', '--no-scripts'],
            $directory,
            [
                'COMPOSER_CACHE_DIR' => $config->environment->cache->composerCacheDirectory(),
            ],
        );
    }
}
