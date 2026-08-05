<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Installation;

use Contao\E2eTestBundle\Cache\CacheConfig;
use Contao\E2eTestBundle\Exception\E2eTestException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final readonly class InstallationPool
{
    public function __construct(private Filesystem $filesystem = new Filesystem())
    {
    }

    public function acquire(CacheConfig $cache, string $fingerprint): InstallationLease
    {
        for ($slot = 0; $slot < 64; ++$slot) {
            $lockPath = Path::join($cache->rootDirectory, 'locks', $fingerprint.'-'.$slot.'.lock');
            $lock = fopen($lockPath, 'c+');

            if (false === $lock) {
                throw new E2eTestException(\sprintf('Could not open the installation lock "%s".', $lockPath));
            }

            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                fclose($lock);
                continue;
            }

            $directory = Path::join($cache->rootDirectory, 'cache/installations', $fingerprint, (string) $slot);
            $this->filesystem->mkdir($directory);

            return new InstallationLease($directory, $slot, $lock);
        }

        throw new E2eTestException('No free E2E installation slot is available.');
    }
}
