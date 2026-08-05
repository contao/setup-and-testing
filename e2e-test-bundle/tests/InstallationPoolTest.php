<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Tests;

use Contao\E2eTestBundle\Cache\CacheConfig;
use Contao\E2eTestBundle\Cache\WorkspaceInitializer;
use Contao\E2eTestBundle\Installation\InstallationPool;
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
}
