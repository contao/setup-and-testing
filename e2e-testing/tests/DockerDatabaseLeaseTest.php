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

use Contao\E2eTesting\Database\DockerDatabaseExclusiveLock;
use Contao\E2eTesting\Database\DockerDatabaseLease;
use Contao\E2eTesting\Database\DockerDatabaseLeaseRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class DockerDatabaseLeaseTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests/database-lease-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testStopsTheDatabaseWhenTheLastLeaseIsReleased(): void
    {
        $stops = new class() {
            private int $count = 0;

            public function increment(): void
            {
                ++$this->count;
            }

            public function count(): int
            {
                return $this->count;
            }
        };
        $lockPath = $this->directory.'/database.lock';
        $first = DockerDatabaseLease::acquire(
            $lockPath,
            static function () use ($stops): void {
                $stops->increment();
            },
        );
        $second = DockerDatabaseLease::acquire(
            $lockPath,
            static function () use ($stops): void {
                $stops->increment();
            },
        );

        $this->assertNull(DockerDatabaseExclusiveLock::acquire($lockPath));
        $first->release();
        $this->assertSame(0, $stops->count());
        $this->assertNull(DockerDatabaseExclusiveLock::acquire($lockPath));
        $second->release();
        $this->assertSame(1, $stops->count());

        $lock = DockerDatabaseExclusiveLock::acquire($lockPath);
        $this->assertInstanceOf(DockerDatabaseExclusiveLock::class, $lock);
        $lock->release();

        $second->release();
        $this->assertSame(1, $stops->count());
    }

    public function testExecutedChildrenDoNotRetainLeasesAfterTheirOwnerIsKilled(): void
    {
        $this->requireSignals();
        $owner = $this->startOwner();

        try {
            $this->assertNull(DockerDatabaseExclusiveLock::acquire($this->directory.'/database.lock'));
            $owner->signal(9);
            $owner->wait();

            $lock = DockerDatabaseExclusiveLock::acquire($this->directory.'/database.lock');
            $this->assertNotNull($lock, 'An orphaned child must not retain its parent database lease.');
            $lock->release();
        } finally {
            $owner->stop(0);
            $this->stopChild();
        }
    }

    public function testRegistryRetainsOneLeaseForTheProcess(): void
    {
        $created = 0;
        $stops = 0;
        $lockPath = $this->directory.'/database.lock';
        $registry = new DockerDatabaseLeaseRegistry();
        $factory = static function () use ($lockPath, &$created, &$stops): DockerDatabaseLease {
            ++$created;

            return DockerDatabaseLease::acquire(
                $lockPath,
                static function () use (&$stops): void {
                    ++$stops;
                },
            );
        };

        $this->assertTrue($registry->acquire($lockPath, $factory));
        $this->assertFalse($registry->acquire($lockPath, $factory));

        $this->assertSame(1, $created);
        $this->assertNull(DockerDatabaseExclusiveLock::acquire($lockPath));

        $registry->release($lockPath);

        $this->assertSame(1, $stops);
        $lock = DockerDatabaseExclusiveLock::acquire($lockPath);
        $this->assertInstanceOf(DockerDatabaseExclusiveLock::class, $lock);
        $lock->release();
    }

    private function requireSignals(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('This regression simulates SIGKILL on a Unix runner.');
        }
    }

    private function stopChild(): void
    {
        if (is_file($this->directory.'/child')) {
            posix_kill((int) file_get_contents($this->directory.'/child'), 9);
        }
    }

    private function startOwner(): Process
    {
        $script = <<<'PHP_WRAP'
            require $argv[1];
            $directory = $argv[2];
            $stop = static function () use ($directory): void { file_put_contents($directory.'/stopped', 'yes'); };
            $lease = \Contao\E2eTesting\Database\DockerDatabaseLease::acquire($directory.'/database.lock', $stop);
            $child = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', 'echo "ready"; sleep(60);']);
            $child->start();
            $child->waitUntil(static fn ($type, $output) => str_contains($output, 'ready'));
            file_put_contents($directory.'/child', (string) $child->getPid());
            echo "ready";
            flush();
            sleep(60);
            PHP_WRAP;
        $process = new Process([PHP_BINARY, '-r', $script, \dirname(__DIR__, 2).'/vendor/autoload.php', $this->directory]);
        $process->start();
        $this->assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'ready')));

        return $process;
    }
}
