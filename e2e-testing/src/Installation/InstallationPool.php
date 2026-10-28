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

use Contao\E2eTesting\Cache\CacheConfig;
use Contao\E2eTesting\Exception\E2eTestException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final readonly class InstallationPool
{
    public function __construct(private Filesystem $filesystem = new Filesystem())
    {
    }

    public function acquire(CacheConfig $cache, string $fingerprint, string|null $configuration = null): InstallationLease
    {
        $fallback = null;

        try {
            for ($slot = 0; $slot < 64; ++$slot) {
                $lease = $this->acquireSlot($cache, $fingerprint, $slot);

                if (!$lease) {
                    continue;
                }

                $marker = Path::join($lease->directory, '.contao-e2e-configuration');

                if (null === $configuration || !is_file($marker) || $configuration === trim((string) file_get_contents($marker))) {
                    return $this->assignConfiguration($lease, $configuration);
                }

                if (!$fallback) {
                    $fallback = $lease;
                } else {
                    $lease->release();
                }
            }

            // Reconfigure a free slot when all slots already belong to other configurations.
            if ($fallback) {
                $lease = $fallback;
                $fallback = null;

                return $this->assignConfiguration($lease, $configuration);
            }

            throw new E2eTestException('No free E2E installation slot is available.');
        } finally {
            $fallback?->release();
        }
    }

    private function acquireSlot(CacheConfig $cache, string $fingerprint, int $slot): InstallationLease|null
    {
        $lockPath = Path::join($cache->rootDirectory, 'locks', $fingerprint.'-'.$slot.'.lock');
        $lock = fopen($lockPath, 'c+');

        if (false === $lock) {
            throw new E2eTestException(\sprintf('Could not open the installation lock "%s".', $lockPath));
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return null;
        }

        $directory = Path::join($cache->installationsDirectory(), $fingerprint, (string) $slot);
        $this->filesystem->mkdir($directory);

        return new InstallationLease($directory, $slot, $lock);
    }

    private function assignConfiguration(InstallationLease $lease, string|null $configuration): InstallationLease
    {
        if (null !== $configuration) {
            $this->filesystem->dumpFile(Path::join($lease->directory, '.contao-e2e-configuration'), $configuration."\n");
        }

        return $lease;
    }
}
