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

use Contao\E2eTestBundle\ManagedEdition\ManagedEditionConfig;
use Contao\E2eTestBundle\Process\ProcessRunner;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final readonly class ComposerInstaller
{
    public function __construct(
        private ProcessRunner $processRunner,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function install(ManagedEditionConfig $config, string $directory): void
    {
        $this->filesystem->dumpFile(Path::join($directory, 'composer.json'), $config->recipe->composer->toJson($directory));
        $runConfig = $config->environment->composer;
        $command = [$runConfig->executable, 'update', '--no-interaction', '--no-progress', '--no-scripts'];

        if ($runConfig->preferLowest) {
            $command[] = '--prefer-lowest';
        }

        if ($runConfig->preferStable) {
            $command[] = '--prefer-stable';
        }

        $this->processRunner->run(
            $command,
            $directory,
            [
                'COMPOSER_CACHE_DIR' => Path::join($config->environment->cache->rootDirectory, 'cache/composer'),
            ],
        );
    }
}
