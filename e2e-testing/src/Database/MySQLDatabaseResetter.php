<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQL80Platform;

/**
 * @internal
 */
final class MySQLDatabaseResetter
{
    public function reset(Connection $connection): void
    {
        $tables = $this->tablesToReset($connection);

        if ([] === $tables) {
            return;
        }

        $foreignKeys = (int) $connection->fetchOne('SELECT @@SESSION.foreign_key_checks');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($tables as $table) {
                $connection->executeStatement('TRUNCATE TABLE '.$connection->getDatabasePlatform()->quoteSingleIdentifier($table));
            }
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = '.$foreignKeys);
        }
    }

    /**
     * @return list<string>
     */
    private function tablesToReset(Connection $connection): array
    {
        $dirty = [];
        $candidates = [];

        foreach ($this->tableCounters($connection) as $row) {
            $table = $row['table'];
            $counter = $row['counter'];
            if ($counter > 1) {
                $dirty[] = $table;
            } else {
                $candidates[] = $table;
            }
        }

        return array_merge($dirty, $this->populatedTables($connection, $candidates));
    }

    /**
     * @return list<array{table: string, counter: int|null}>
     */
    private function tableCounters(Connection $connection): array
    {
        if (!$connection->getDatabasePlatform() instanceof MySQL80Platform) {
            return $this->readTableCounters($connection);
        }

        // MySQL caches AUTO_INCREMENT statistics, so an empty table may still need
        // truncation even when its cached counter says otherwise.
        $expiry = (int) $connection->fetchOne('SELECT @@SESSION.information_schema_stats_expiry');
        $connection->executeStatement('SET SESSION information_schema_stats_expiry = 0');

        try {
            return $this->readTableCounters($connection);
        } finally {
            $connection->executeStatement('SET SESSION information_schema_stats_expiry = '.$expiry);
        }
    }

    /**
     * @return list<array{table: string, counter: int|null}>
     */
    private function readTableCounters(Connection $connection): array
    {
        $rows = $connection->fetchAllAssociative("SELECT TABLE_NAME AS table_name, AUTO_INCREMENT AS auto_increment FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
        $counters = [];

        foreach ($rows as $row) {
            $counters[] = ['table' => (string) $row['table_name'], 'counter' => null === $row['auto_increment'] ? null : (int) $row['auto_increment']];
        }

        return $counters;
    }

    /**
     * @param list<string> $tables
     *
     * @return list<string>
     */
    private function populatedTables(Connection $connection, array $tables): array
    {
        // InnoDB row-count statistics are estimates. Probe the tables themselves so rows
        // written outside the fixture set cannot survive a reset.
        $populated = [];

        foreach (array_chunk($tables, 100) as $chunk) {
            $queries = [];

            foreach ($chunk as $table) {
                $queries[] = 'SELECT '.$connection->quote($table).' AS table_name WHERE EXISTS (SELECT 1 FROM '.$connection->getDatabasePlatform()->quoteSingleIdentifier($table).')';
            }

            array_push($populated, ...array_map(static fn ($table): string => (string) $table, $connection->fetchFirstColumn(implode(' UNION ALL ', $queries))));
        }

        return $populated;
    }
}
