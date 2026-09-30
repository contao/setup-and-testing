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
        $this->assertSame($first['managed_edition']['fingerprint'], $second['managed_edition']['fingerprint']);
        $this->assertNotSame($first['playwright']['fingerprint'], $first['managed_edition']['fingerprint']);
        $this->assertSame($this->directory.'/browsers', $first['playwright']['path']);
        $this->assertSame(
            [
                'composer' => $this->directory.'/project/.contao-e2e/cache/composer',
                'dependency_locks' => $this->directory.'/project/.contao-e2e/cache/dependency-locks',
                'installations' => $this->directory.'/project/.contao-e2e/cache/installations',
            ],
            $first['managed_edition']['paths'],
        );

        $this->assertArrayNotHasKey('database', $first['managed_edition']['paths']);
        $this->assertArrayNotHasKey('locks', $first['managed_edition']['paths']);
        $this->assertArrayNotHasKey('runtime', $first['managed_edition']['paths']);
        $this->assertArrayNotHasKey('failures', $first['managed_edition']['paths']);
    }

    public function testPlaywrightVersionInvalidatesOnlyTheBrowserFingerprint(): void
    {
        $config = CacheConfig::forProject($this->directory.'/project');
        $initial = $this->factory()->create($config);
        $this->writePlaywrightVersion('1.64.0');
        $changed = $this->factory()->create($config);

        $this->assertNotSame($initial['playwright']['fingerprint'], $changed['playwright']['fingerprint']);
        $this->assertSame($initial['managed_edition']['fingerprint'], $changed['managed_edition']['fingerprint']);
    }

    public function testExplainsHowToPrepareMissingPlaywrightDependencies(): void
    {
        $this->expectException(E2eTestException::class);
        $this->expectExceptionMessage('vendor/bin/playwright-install');

        (new CacheMetadataFactory(playwrightPackageDirectory: $this->directory.'/missing-package'))
            ->create(CacheConfig::forProject($this->directory.'/project'))
        ;
    }

    public function testUsesConfiguredPlaywrightBrowserDirectory(): void
    {
        putenv('PLAYWRIGHT_BROWSERS_PATH=relative-browser-cache');
        putenv('INIT_CWD='.$this->directory);

        try {
            $metadata = (new CacheMetadataFactory(playwrightPackageDirectory: $this->directory.'/package'))
                ->create(CacheConfig::forProject($this->directory.'/project'))
            ;
            $this->assertSame($this->directory.'/relative-browser-cache', $metadata['playwright']['path']);
        } finally {
            putenv('PLAYWRIGHT_BROWSERS_PATH');
            putenv('INIT_CWD');
        }
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('managedEditionCompatibilityProvider')]
    public function testRelevantCompatibilityChangesInvalidateOnlyTheManagedEditionFingerprint(array $override): void
    {
        $config = CacheConfig::forProject($this->directory.'/project');
        $initial = $this->factory()->create($config);
        $changed = $this->factory($override)->create($config);

        $this->assertSame($initial['playwright']['fingerprint'], $changed['playwright']['fingerprint']);
        $this->assertNotSame($initial['managed_edition']['fingerprint'], $changed['managed_edition']['fingerprint']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function managedEditionCompatibilityProvider(): iterable
    {
        yield 'cache format' => [['cache_format' => 2]];
        yield 'PHP version' => [['php' => '9.0']];
        yield 'operating system' => [['operating_system' => 'ExampleOS']];
        yield 'architecture' => [['architecture' => 'example-architecture']];
        yield 'E2E package' => [['packages' => ['contao/e2e-testing' => '2.0.0.0']]];
        yield 'recipe package' => [['packages' => ['contao/installation-recipe' => '2.0.0.0']]];
        yield 'Composer configuration' => [['composer' => ['prefer_lowest' => true]]];
    }

    /**
     * @param array<string, mixed> $compatibility
     */
    private function factory(array $compatibility = []): CacheMetadataFactory
    {
        return new CacheMetadataFactory(
            playwrightPackageDirectory: $this->directory.'/package',
            browserDirectory: $this->directory.'/browsers',
            compatibilityOverrides: $compatibility,
            operatingSystem: 'Linux',
            architecture: 'x86_64',
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
