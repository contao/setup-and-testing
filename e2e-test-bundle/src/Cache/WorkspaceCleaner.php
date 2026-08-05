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

final readonly class WorkspaceCleaner
{
    public function __construct(private Filesystem $filesystem = new Filesystem())
    {
    }

    public function clearCache(CacheConfig $config): void
    {
        $this->assertManaged($config);
        $this->filesystem->remove(Path::join($config->rootDirectory, 'cache'));
        (new WorkspaceInitializer($this->filesystem))->initialize($config);
    }

    public function clearFailures(CacheConfig $config): void
    {
        $this->assertManaged($config);
        $this->filesystem->remove(Path::join($config->rootDirectory, 'failures'));
        (new WorkspaceInitializer($this->filesystem))->initialize($config);
    }

    private function assertManaged(CacheConfig $config): void
    {
        $marker = Path::join($config->rootDirectory, '.managed-by-contao-e2e');

        if (!is_file($marker)) {
            throw new UnsafeWorkspaceException(\sprintf('The E2E workspace "%s" has no safety marker.', $config->rootDirectory));
        }
    }
}
