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

use Contao\E2eTesting\Cache\CacheMetadataFactory;
use Contao\E2eTesting\Command\CacheMetadataCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class CacheMetadataCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests/cache-command-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir([
            $this->directory.'/package/bin/node_modules/playwright',
            $this->directory.'/package/bin/node_modules/playwright-core',
        ]);
        $filesystem->dumpFile(
            $this->directory.'/package/bin/node_modules/playwright/package.json',
            '{"name":"playwright","version":"1.63.0"}',
        );
        $filesystem->dumpFile(
            $this->directory.'/package/bin/node_modules/playwright-core/browsers.json',
            '{"browsers":[{"name":"firefox","revision":"1543","installByDefault":true}]}',
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testWritesPortableCacheKeysAndProducesJsonOutput(): void
    {
        $cacheDirectory = $this->directory.'/workspace';
        putenv('CONTAO_E2E_DIRECTORY='.$cacheDirectory);
        $tester = new CommandTester(new CacheMetadataCommand($this->metadataFactory()));

        try {
            $this->assertSame(0, $tester->execute([]));
            $metadata = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            putenv('CONTAO_E2E_DIRECTORY');
        }

        $this->assertSame(1, $metadata['schema_version']);
        $this->assertSame(
            $metadata['playwright']['fingerprint']."\n",
            file_get_contents($cacheDirectory.'/cache-keys/playwright'),
        );
        $this->assertSame(
            $metadata['e2e']['fingerprint']."\n",
            file_get_contents($cacheDirectory.'/cache-keys/e2e'),
        );
        $this->assertSame(
            $cacheDirectory.'/cache/playwright',
            $metadata['playwright']['path'],
        );
        $this->assertSame(
            $cacheDirectory.'/cache/e2e',
            $metadata['e2e']['path'],
        );
    }

    private function metadataFactory(): CacheMetadataFactory
    {
        return new CacheMetadataFactory(
            playwrightPackageDirectory: $this->directory.'/package',
            operatingSystem: 'Linux',
            architecture: 'x86_64',
        );
    }
}
