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

use Contao\E2eTestBundle\Exception\UnsafeWorkspaceException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final readonly class WorkspaceInitializer
{
    public function __construct(private Filesystem $filesystem = new Filesystem())
    {
    }

    public function initialize(CacheConfig $config): void
    {
        $this->assertSafe($config);
        $this->filesystem->mkdir([
            Path::join($config->rootDirectory, 'cache/composer'),
            Path::join($config->rootDirectory, 'cache/dependency-locks'),
            Path::join($config->rootDirectory, 'cache/installations'),
            Path::join($config->rootDirectory, 'cache/paratest'),
            Path::join($config->rootDirectory, 'database'),
            Path::join($config->rootDirectory, 'failures'),
            Path::join($config->rootDirectory, 'locks'),
            Path::join($config->rootDirectory, 'runtime'),
        ]);
        $this->filesystem->dumpFile(Path::join($config->rootDirectory, '.gitignore'), "*\n!.gitignore\n");
        $this->filesystem->dumpFile(Path::join($config->rootDirectory, '.managed-by-contao-e2e'), "Managed by contao/e2e-test-bundle.\n");
    }

    private function assertSafe(CacheConfig $config): void
    {
        if (Path::canonicalize($config->projectDirectory) === Path::canonicalize($config->rootDirectory)) {
            throw new UnsafeWorkspaceException('The E2E workspace must not be the project root.');
        }

        if (is_link($config->rootDirectory)) {
            throw new UnsafeWorkspaceException('The E2E workspace must not be a symbolic link.');
        }

        if ($this->filesystem->exists($config->rootDirectory) && '.contao-e2e' !== basename($config->rootDirectory)) {
            $marker = Path::join($config->rootDirectory, '.managed-by-contao-e2e');

            if (!is_file($marker)) {
                throw new UnsafeWorkspaceException(\sprintf('The custom E2E workspace "%s" has no safety marker.', $config->rootDirectory));
            }
        }
    }
}
