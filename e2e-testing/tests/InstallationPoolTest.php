<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Tests;

use Contao\E2eTesting\Cache\CacheConfig;
use Contao\E2eTesting\Cache\WorkspaceInitializer;
use Contao\E2eTesting\Installation\InstallationPool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class InstallationPoolTest extends TestCase
{
    public function testUsesSeparateSlotsUntilALeaseIsReleased(): void
    {
        $project = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests/pool-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($project);
        $cache = CacheConfig::forProject($project);
        (new WorkspaceInitializer())->initialize($cache);
        $pool = new InstallationPool();

        $first = $pool->acquire($cache, 'fingerprint');
        $second = $pool->acquire($cache, 'fingerprint');
        $this->assertSame(0, $first->slot);
        $this->assertSame(1, $second->slot);

        $first->release();
        $reused = $pool->acquire($cache, 'fingerprint');
        $this->assertSame(0, $reused->slot);
    }

    public function testKeepsReleasedApplicationConfigurationsInSeparateSlots(): void
    {
        $project = sys_get_temp_dir().'/pool-configurations-'.bin2hex(random_bytes(6));
        $cache = CacheConfig::forProject($project);
        (new WorkspaceInitializer())->initialize($cache);
        $pool = new InstallationPool();

        try {
            $first = $pool->acquire($cache, 'dependencies', 'application-a');
            $firstDirectory = $first->directory;
            (new Filesystem())->dumpFile($firstDirectory.'/prepared', 'application-a');
            $first->release();
            $second = $pool->acquire($cache, 'dependencies', 'application-b');
            $this->assertNotSame($firstDirectory, $second->directory);
            $second->release();
            $reused = $pool->acquire($cache, 'dependencies', 'application-a');
            $this->assertSame($firstDirectory, $reused->directory);
            $this->assertSame('application-a', file_get_contents($reused->directory.'/prepared'));
            $parallel = $pool->acquire($cache, 'dependencies', 'application-a');
            $this->assertNotSame($reused->slot, $parallel->slot);
            $parallel->release();
            $reused->release();
        } finally {
            (new Filesystem())->remove($project);
        }
    }

    public function testAdoptsExistingCachedInstallationsWithoutDiscardingTheirFiles(): void
    {
        $project = sys_get_temp_dir().'/pool-existing-'.bin2hex(random_bytes(6));
        $cache = CacheConfig::forProject($project);
        (new WorkspaceInitializer())->initialize($cache);
        $pool = new InstallationPool();

        try {
            $existing = $pool->acquire($cache, 'dependencies');
            $directory = $existing->directory;
            (new Filesystem())->dumpFile($directory.'/project/vendor/installed', 'cached dependencies');
            $existing->release();
            $adopted = $pool->acquire($cache, 'dependencies', 'configuration');
            $this->assertSame($directory, $adopted->directory);
            $this->assertSame('cached dependencies', file_get_contents($adopted->directory.'/project/vendor/installed'));
            $adopted->release();
        } finally {
            (new Filesystem())->remove($project);
        }
    }

    public function testReconfiguresAFreeSlotWhenAllSlotsAlreadyHaveConfigurations(): void
    {
        $project = sys_get_temp_dir().'/pool-capacity-'.bin2hex(random_bytes(6));
        $cache = CacheConfig::forProject($project);
        (new WorkspaceInitializer())->initialize($cache);
        $pool = new InstallationPool();

        try {
            for ($i = 0; $i < 64; ++$i) {
                $lease = $pool->acquire($cache, 'dependencies', 'configuration-'.$i);
                $lease->release();
            }

            $lease = $pool->acquire($cache, 'dependencies', 'new-configuration');
            $this->assertSame(0, $lease->slot);
            $lease->release();
            $lease = $pool->acquire($cache, 'dependencies', 'new-configuration');
            $this->assertSame(0, $lease->slot);
            $lease->release();
        } finally {
            (new Filesystem())->remove($project);
        }
    }
}
