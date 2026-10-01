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
use Contao\E2eTesting\Cache\CacheMetadataFactory;
use Contao\E2eTesting\Exception\E2eTestException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class CacheMetadataFactoryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = Path::join(\dirname(__DIR__, 2), '.contao-e2e/runtime/unit-tests/cache-metadata-'.bin2hex(random_bytes(6)));
        $filesystem = new Filesystem();
        $filesystem->mkdir([
            $this->directory.'/package/bin/node_modules/playwright',
            $this->directory.'/package/bin/node_modules/playwright-core',
        ]);
        $this->writePlaywrightVersion('1.63.0');
        $filesystem->dumpFile($this->directory.'/package/bin/node_modules/playwright-core/browsers.json', <<<'JSON'
            {
                "browsers": [
                    {"name": "chromium", "revision": "1243", "installByDefault": true},
                    {"name": "firefox", "revision": "1543", "installByDefault": true},
                    {"name": "webkit", "revision": "2359", "revisionOverrides": {"mac14-arm64": "2251"}, "installByDefault": true},
                    {"name": "android", "revision": "1001", "installByDefault": false}
                ]
            }
            JSON);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testProducesDeterministicSeparateFingerprintsAndStablePaths(): void
    {
        $factory = $this->factory();
        $config = CacheConfig::forProject($this->directory.'/project');
        $first = $factory->create($config);
        $second = $factory->create($config);

        $this->assertSame($first['playwright']['fingerprint'], $second['playwright']['fingerprint']);
        $this->assertSame($first['e2e']['fingerprint'], $second['e2e']['fingerprint']);
        $this->assertNotSame($first['playwright']['fingerprint'], $first['e2e']['fingerprint']);
        $this->assertSame($this->directory.'/project/.contao-e2e/cache/playwright', $first['playwright']['path']);
        $this->assertSame(
            $this->directory.'/project/.contao-e2e/cache/e2e',
            $first['e2e']['path'],
        );
        $this->assertSame(['fingerprint', 'path'], array_keys($first['playwright']));
        $this->assertSame(['fingerprint', 'path'], array_keys($first['e2e']));
    }

    public function testPlaywrightMetadataInvalidatesOnlyTheBrowserFingerprint(): void
    {
        $config = CacheConfig::forProject($this->directory.'/project');
        $initial = $this->factory()->create($config);
        $this->writePlaywrightVersion('1.64.0');
        $versionChanged = $this->factory()->create($config);

        $this->assertNotSame($initial['playwright']['fingerprint'], $versionChanged['playwright']['fingerprint']);
        $this->assertSame($initial['e2e']['fingerprint'], $versionChanged['e2e']['fingerprint']);

        (new Filesystem())->dumpFile(
            $this->directory.'/package/bin/node_modules/playwright-core/browsers.json',
            '{"browsers":[{"name":"firefox","revision":"1544","installByDefault":true}]}',
        );
        $revisionChanged = $this->factory()->create($config);

        $this->assertNotSame($versionChanged['playwright']['fingerprint'], $revisionChanged['playwright']['fingerprint']);
        $this->assertSame($versionChanged['e2e']['fingerprint'], $revisionChanged['e2e']['fingerprint']);
    }

    public function testExplainsHowToPrepareMissingPlaywrightDependencies(): void
    {
        $this->expectException(E2eTestException::class);
        $this->expectExceptionMessage('vendor/bin/playwright-install');

        (new CacheMetadataFactory(playwrightPackageDirectory: $this->directory.'/missing-package'))
            ->create(CacheConfig::forProject($this->directory.'/project'))
        ;
    }

    public function testOperatingSystemVersionInvalidatesOnlyTheBrowserFingerprint(): void
    {
        $config = CacheConfig::forProject($this->directory.'/project');
        $initial = $this->factory(operatingSystemVersion: 'ubuntu-22.04')->create($config);
        $changed = $this->factory(operatingSystemVersion: 'ubuntu-24.04')->create($config);
        $differentDistribution = $this->factory(operatingSystemVersion: 'debian-12')->create($config);

        $this->assertNotSame($initial['playwright']['fingerprint'], $changed['playwright']['fingerprint']);
        $this->assertNotSame($changed['playwright']['fingerprint'], $differentDistribution['playwright']['fingerprint']);
        $this->assertSame($initial['e2e']['fingerprint'], $changed['e2e']['fingerprint']);
        $this->assertSame($changed['e2e']['fingerprint'], $differentDistribution['e2e']['fingerprint']);
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('e2eCompatibilityProvider')]
    public function testRelevantCompatibilityChangesInvalidateOnlyTheE2eFingerprint(array $override): void
    {
        $config = CacheConfig::forProject($this->directory.'/project');
        $initial = $this->factory()->create($config);
        $changed = $this->factory($override)->create($config);

        $this->assertSame($initial['playwright']['fingerprint'], $changed['playwright']['fingerprint']);
        $this->assertNotSame($initial['e2e']['fingerprint'], $changed['e2e']['fingerprint']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function e2eCompatibilityProvider(): iterable
    {
        yield 'PHP version' => [['php' => '9.0']];
        yield 'operating system' => [['operating_system' => 'ExampleOS']];
        yield 'architecture' => [['architecture' => 'example-architecture']];
        yield 'E2E package' => [['packages' => ['contao/e2e-testing' => '2.0.0.0']]];
        yield 'recipe package' => [['packages' => ['contao/installation-recipe' => '2.0.0.0']]];
        yield 'Composer configuration' => [['composer' => ['COMPOSER_PREFER_LOWEST' => '1']]];
    }

    /**
     * @param array<string, mixed> $compatibility
     */
    private function factory(array $compatibility = [], string $operatingSystemVersion = 'ubuntu-24.04'): CacheMetadataFactory
    {
        return new CacheMetadataFactory(
            playwrightPackageDirectory: $this->directory.'/package',
            compatibilityOverrides: $compatibility,
            operatingSystem: 'Linux',
            architecture: 'x86_64',
            operatingSystemVersion: $operatingSystemVersion,
        );
    }

    private function writePlaywrightVersion(string $version): void
    {
        (new Filesystem())->dumpFile(
            $this->directory.'/package/bin/node_modules/playwright/package.json',
            json_encode(['name' => 'playwright', 'version' => $version], JSON_THROW_ON_ERROR),
        );
    }
}
