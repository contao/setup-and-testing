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

use Contao\E2eTesting\Exception\E2eTestException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

final class DatabaseResetter
{
    public function reset(Connection $connection): void
    {
        if (!$connection->isAutoCommit()) {
            throw new E2eTestException('Database resets require auto-commit to be enabled.');
        }

        if ($connection->isTransactionActive()) {
            throw new E2eTestException('Database resets require a connection without an active transaction.');
        }

        $platform = $connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            (new MySQLDatabaseResetter())->reset($connection);

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->resetSqlite($connection);

            return;
        }

        if ($platform instanceof PostgreSQLPlatform) {
            $this->resetPostgreSql($connection);

            return;
        }

        throw new E2eTestException('Database resets support MySQL, MariaDB, SQLite and PostgreSQL only.');
    }

    private function resetPostgreSql(Connection $connection): void
    {
        // PostgreSQL quotes each identifier separately, preserving dots inside names.
        $tables = $connection->fetchFirstColumn("SELECT quote_ident(schemaname) || '.' || quote_ident(tablename) FROM pg_catalog.pg_tables WHERE schemaname NOT LIKE 'pg\\_%' AND schemaname <> 'information_schema' ORDER BY schemaname, tablename");

        if ([] === $tables) {
            return;
        }

        $quoted = array_map(static fn ($table): string => (string) $table, $tables);
        $connection->executeStatement('TRUNCATE TABLE '.implode(', ', $quoted).' RESTART IDENTITY');
    }

    private function resetSqlite(Connection $connection): void
    {
        $tables = $connection->createSchemaManager()->listTableNames();

        if ([] === $tables) {
            return;
        }

        $foreignKeys = (int) $connection->fetchOne('PRAGMA foreign_keys');
        $connection->executeStatement('PRAGMA foreign_keys = OFF');

        try {
            $connection->transactional(fn () => $this->clearSqliteTables($connection, $tables));
        } finally {
            $connection->executeStatement('PRAGMA foreign_keys = '.$foreignKeys);
        }
    }

    /**
     * @param list<string> $tables
     */
    private function clearSqliteTables(Connection $connection, array $tables): void
    {
        foreach ($tables as $table) {
            $connection->executeStatement('DELETE FROM '.$connection->getDatabasePlatform()->quoteSingleIdentifier($table));
        }

        if (false === $connection->fetchOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'")) {
            return;
        }

        foreach (array_chunk($tables, 100) as $chunk) {
            $placeholders = implode(', ', array_fill(0, \count($chunk), '?'));
            $connection->executeStatement('DELETE FROM sqlite_sequence WHERE name IN ('.$placeholders.')', $chunk);
        }
    }
}
