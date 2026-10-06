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

use Contao\E2eTesting\Cache\CachedSourceFingerprint;
use Contao\E2eTesting\Cache\SourceFingerprint;
use Contao\E2eTesting\Cache\SourceFingerprintInterface;
use Contao\InstallationRecipe\Cache\InMemoryCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CachedSourceFingerprintTest extends TestCase
{
    public function testSharedCacheKeepsSourceSnapshotUntilExplicitlyCleared(): void
    {
        $directory = sys_get_temp_dir().'/source-cache-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($directory.'/Example.php', '<?php return 1;');

        $cache = new InMemoryCache();
        $fingerprint = new CachedSourceFingerprint(new SourceFingerprint(), $cache);

        try {
            $initial = $fingerprint->calculate($directory);
            $filesystem->dumpFile($directory.'/Example.php', '<?php return 2;');

            $this->assertSame($initial, (new CachedSourceFingerprint(new SourceFingerprint(), $cache))->calculate($directory));
            $this->assertNotSame($initial, (new CachedSourceFingerprint(new SourceFingerprint(), new InMemoryCache()))->calculate($directory));
            $cache->clear();
            $this->assertNotSame($initial, $fingerprint->calculate($directory));
        } finally {
            $filesystem->remove($directory);
        }
    }

    public function testCanonicalPathsShareTheSameCachedCalculation(): void
    {
        $source = $this->createMock(SourceFingerprintInterface::class);
        $source
            ->expects($this->once())
            ->method('calculate')
            ->with('/example/package')
            ->willReturn('fingerprint')
        ;
        $cache = new InMemoryCache();
        $first = new CachedSourceFingerprint($source, $cache);
        $second = new CachedSourceFingerprint($source, $cache);

        $this->assertSame('fingerprint', $first->calculate('/example/./package'));
        $this->assertSame('fingerprint', $second->calculate('/example/package'));
    }
}
