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
use Contao\E2eTesting\Command\GithubOutputWriter;
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

    public function testProducesJsonOutput(): void
    {
        $tester = new CommandTester(new CacheMetadataCommand($this->metadataFactory()));

        $this->assertSame(0, $tester->execute([]));
        $metadata = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $metadata['schema_version']);
        $this->assertSame('1.63.0', $metadata['playwright']['version']);
        $this->assertArrayHasKey('fingerprint', $metadata['playwright']);
        $this->assertArrayHasKey('fingerprint', $metadata['managed_edition']);
        $this->assertSame(
            [
                'composer',
                'dependency_locks',
                'installations',
            ],
            array_keys($metadata['managed_edition']['paths']),
        );
    }

    public function testWritesEscapedMultilineGithubActionsOutput(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'contao-e2e-output-');
        $this->assertIsString($path);
        $writer = new GithubOutputWriter();

        try {
            $writer->write($path, [
                'single_value' => 'example',
                'multiple_paths' => "first path\nsecond path",
            ]);
            $contents = file_get_contents($path);
            $this->assertIsString($contents);
            $this->assertMatchesRegularExpression('/single_value<<(CONTAO_E2E_[A-F0-9]+)\nexample\n\\1\n/', $contents);
            $this->assertMatchesRegularExpression('/multiple_paths<<(CONTAO_E2E_[A-F0-9]+)\nfirst path\nsecond path\n\\1\n/', $contents);
        } finally {
            unlink($path);
        }
    }

    public function testCommandWritesGithubActionsOutputs(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'contao-e2e-command-output-');
        $this->assertIsString($path);
        putenv('GITHUB_OUTPUT='.$path);
        $tester = new CommandTester(new CacheMetadataCommand($this->metadataFactory()));

        try {
            $this->assertSame(0, $tester->execute(['--github-output' => true]));
            $contents = file_get_contents($path);
            $this->assertIsString($contents);
            $this->assertStringContainsString('playwright_fingerprint<<', $contents);
            $this->assertStringContainsString('playwright_path<<', $contents);
            $this->assertStringContainsString('managed_edition_fingerprint<<', $contents);
            $this->assertStringContainsString('managed_edition_paths<<', $contents);
            $this->assertStringContainsString("cache/composer\n", $contents);
            $this->assertStringContainsString("cache/dependency-locks\n", $contents);
            $this->assertStringContainsString("cache/installations\n", $contents);
        } finally {
            putenv('GITHUB_OUTPUT');
            unlink($path);
        }
    }

    private function metadataFactory(): CacheMetadataFactory
    {
        return new CacheMetadataFactory(
            playwrightPackageDirectory: $this->directory.'/package',
            browserDirectory: $this->directory.'/browsers',
            operatingSystem: 'Linux',
            architecture: 'x86_64',
        );
    }
}
