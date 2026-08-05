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
use Contao\E2eTestBundle\Database\DockerDatabaseConfig;
use Contao\E2eTestBundle\Exception\E2eTestException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DockerDatabaseConfigTest extends TestCase
{
    #[DataProvider('provideDatabaseTypes')]
    public function testCreatesConfigurationFromTheEnvironment(string $type, string $image, string $passwordEnvironment): void
    {
        putenv('CONTAO_E2E_DATABASE_TYPE='.$type);
        putenv('CONTAO_E2E_DATABASE_IMAGE='.$image);

        try {
            $config = DockerDatabaseConfig::fromEnvironment();
        } finally {
            putenv('CONTAO_E2E_DATABASE_TYPE');
            putenv('CONTAO_E2E_DATABASE_IMAGE');
        }

        $this->assertSame($image, $config->image);
        $this->assertSame($passwordEnvironment, $config->rootPasswordEnvironment);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideDatabaseTypes(): iterable
    {
        yield 'MariaDB' => ['mariadb', 'mariadb:10.11', 'MARIADB_ROOT_PASSWORD'];
        yield 'MySQL' => ['mysql', 'mysql:8.0', 'MYSQL_ROOT_PASSWORD'];
    }

    public function testRejectsUnknownDatabaseTypes(): void
    {
        putenv('CONTAO_E2E_DATABASE_TYPE=postgresql');

        try {
            $this->expectException(E2eTestException::class);
            DockerDatabaseConfig::fromEnvironment();
        } finally {
            putenv('CONTAO_E2E_DATABASE_TYPE');
        }
    }

    public function testSeparatesStorageByImageAndType(): void
    {
        $this->assertNotSame(
            DockerDatabaseConfig::mariaDb('example/database:1')->storageKey(),
            DockerDatabaseConfig::mysql('example/database:1')->storageKey(),
        );
        $this->assertNotSame(
            DockerDatabaseConfig::mariaDb('mariadb:10.11')->storageKey(),
            DockerDatabaseConfig::mariaDb('mariadb:11.4')->storageKey(),
        );
    }

    public function testUsesASeparateStorageDirectoryForEveryVariant(): void
    {
        $cache = CacheConfig::forProject(\dirname(__DIR__, 2));

        $this->assertSame(
            $cache->rootDirectory.'/database/data',
            DockerDatabaseConfig::mariaDb()->storageDirectory($cache),
        );
        $this->assertSame(
            $cache->rootDirectory.'/database/'.DockerDatabaseConfig::mysql('mysql:8.0')->storageKey().'/data',
            DockerDatabaseConfig::mysql('mysql:8.0')->storageDirectory($cache),
        );
        $this->assertSame(
            $cache->rootDirectory.'/database/data/.contao-e2e-database',
            DockerDatabaseConfig::mariaDb()->storageMarker($cache),
        );
    }
}
