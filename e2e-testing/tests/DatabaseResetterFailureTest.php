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

use Contao\E2eTesting\Database\DatabaseResetter;
use Contao\E2eTesting\Exception\E2eTestException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DatabaseResetterFailureTest extends TestCase
{
    public function testRejectsUnsupportedPlatformsBeforeExecutingSql(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('isAutoCommit')
            ->willReturn(true)
        ;

        $connection
            ->method('getDatabasePlatform')
            ->willReturn(new SQLServerPlatform())
        ;

        $connection
            ->expects($this->never())
            ->method('executeStatement')
        ;
        $this->expectException(E2eTestException::class);
        $this->expectExceptionMessage('MySQL, MariaDB, SQLite and PostgreSQL only');

        (new DatabaseResetter())->reset($connection);
    }

    public function testMySqlRestoresSessionSettingsWhenTruncationFails(): void
    {
        $connection = $this->mySqlConnection();
        $connection
            ->method('fetchAllAssociative')
            ->willReturn([['table_name' => 'example', 'auto_increment' => 2]])
        ;
        $statements = [];
        $failure = new \RuntimeException('Truncation failed');
        $connection
            ->expects($this->atLeastOnce())
            ->method('executeStatement')
            ->willReturnCallback(
                static function (string $sql) use (&$statements, $failure): int {
                    $statements[] = $sql;

                    if (str_starts_with($sql, 'TRUNCATE ')) {
                        throw $failure;
                    }

                    return 0;
                },
            )
        ;

        try {
            (new DatabaseResetter())->reset($connection);
            $this->fail('Expected truncation to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(
            [
                'SET SESSION information_schema_stats_expiry = 0',
                'SET SESSION information_schema_stats_expiry = 60',
                'SET FOREIGN_KEY_CHECKS = 0',
                'TRUNCATE TABLE `example`',
                'SET FOREIGN_KEY_CHECKS = 0',
            ],
            $statements,
        );
    }

    public function testMySqlRestoresStatisticsExpiryWhenMetadataReadFails(): void
    {
        $connection = $this->mySqlConnection();
        $connection
            ->method('fetchAllAssociative')
            ->willThrowException(new \RuntimeException('Metadata failed'))
        ;

        $statements = [];
        $connection
            ->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(
                static function (string $sql) use (&$statements): int {
                    $statements[] = $sql;

                    return 0;
                },
            )
        ;

        try {
            (new DatabaseResetter())->reset($connection);
            $this->fail('Expected metadata reading to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Metadata failed', $exception->getMessage());
        }

        $this->assertSame(['SET SESSION information_schema_stats_expiry = 0', 'SET SESSION information_schema_stats_expiry = 60'], $statements);
    }

    private function mySqlConnection(): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('isAutoCommit')
            ->willReturn(true)
        ;

        $connection
            ->method('getDatabasePlatform')
            ->willReturn(new MySQL80Platform())
        ;

        $connection
            ->method('fetchOne')
            ->willReturnCallback(static fn (string $sql): int => str_contains($sql, 'stats_expiry') ? 60 : 0)
        ;

        return $connection;
    }
}
