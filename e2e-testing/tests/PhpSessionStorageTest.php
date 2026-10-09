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

use Contao\E2eTesting\Browser\Session\PhpSessionStorage;
use Contao\E2eTesting\Browser\Session\SessionSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class PhpSessionStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/session paths '.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'parent traversal' => ['../outside/sess_initial'];
        yield 'nested traversal' => ['prod/../../outside/sess_initial'];
        yield 'absolute Unix path' => ['/outside/sess_initial'];
        yield 'absolute Windows path' => ['C:/outside/sess_initial'];
        yield 'invalid file name' => ['prod/other_file'];
        yield 'null byte' => ["prod\0/sess_initial"];
    }

    #[DataProvider('invalidPaths')]
    public function testRejectsInvalidSnapshotPathsBeforeWritingAnyFile(string $path): void
    {
        $storage = new PhpSessionStorage($this->directory.'/sessions');
        $snapshot = new SessionSnapshot([], ['prod/sess_valid' => 'valid', $path => 'invalid']);

        try {
            $storage->restore($snapshot);
            $this->fail('Invalid paths must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('within the session directory', $exception->getMessage());
        }

        $this->assertDirectoryDoesNotExist($this->directory);
    }

    public function testDoesNotRestoreThroughASymbolicLink(): void
    {
        if ('Windows' === PHP_OS_FAMILY) {
            $this->markTestSkipped('Symbolic links require additional Windows permissions.');
        }

        $filesystem = new Filesystem();
        $filesystem->mkdir([$this->directory.'/sessions', $this->directory.'/outside']);
        $filesystem->symlink($this->directory.'/outside', $this->directory.'/sessions/prod');

        $storage = new PhpSessionStorage($this->directory.'/sessions');
        $snapshot = new SessionSnapshot([], ['prod/sess_initial' => 'session data']);

        try {
            $storage->restore($snapshot);
            $this->fail('Symbolic links must not redirect restored session files.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('symbolic links', $exception->getMessage());
        }

        $this->assertSame([], glob($this->directory.'/outside/*'));
    }

    public function testWaitsForAnActiveSessionWriteBeforeCapturing(): void
    {
        $path = $this->directory.'/sess_initial';
        (new Filesystem())->dumpFile($path, 'old data');
        $process = new Process([PHP_BINARY, __DIR__.'/Fixtures/session-file-writer.php', $path]);
        $process->start();

        try {
            $this->assertTrue($process->waitUntil(static fn (string $type, string $output): bool => 'locked' === $output));
            $storage = new PhpSessionStorage($this->directory);
            $cookies = [['name' => 'custom_session', 'value' => 'initial', 'domain' => 'localhost', 'path' => '/', 'expires' => -1, 'httpOnly' => true, 'secure' => false, 'sameSite' => 'Lax']];
            $snapshot = $storage->capture($cookies);
            $this->assertSame(['sess_initial' => 'complete data'], $snapshot->files);
            $this->assertSame(0, $process->wait());
        } finally {
            $process->stop();
        }
    }

    public function testRejectsAnEmptySessionDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PhpSessionStorage('');
    }

    public function testPreservesCookiesWhenTheSessionDirectoryDoesNotExist(): void
    {
        $storage = new PhpSessionStorage($this->directory);
        $cookies = [['name' => 'custom_session', 'value' => 'unavailable', 'domain' => 'localhost', 'path' => '/', 'expires' => -1, 'httpOnly' => true, 'secure' => false, 'sameSite' => 'Lax']];
        $snapshot = $storage->capture($cookies);
        $this->assertSame([], $snapshot->files);
        $this->assertSame($cookies, $storage->restore($snapshot));
    }
}
