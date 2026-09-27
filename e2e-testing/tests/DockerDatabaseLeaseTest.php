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

        $registry->acquire($lockPath, $factory);
        $registry->acquire($lockPath, $factory);

        $this->assertSame(1, $created);
        $this->assertNull(DockerDatabaseExclusiveLock::acquire($lockPath));

        $registry->release($lockPath);

        $this->assertSame(1, $stops);
        $lock = DockerDatabaseExclusiveLock::acquire($lockPath);
        $this->assertInstanceOf(DockerDatabaseExclusiveLock::class, $lock);
        $lock->release();
    }
}
