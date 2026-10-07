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
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type Params from DriverManager
 */
final class DatabaseResetterTest extends TestCase
{
    private Connection $connection;

    /**
     * @phpstan-var Params
     */
    private array $connectionParams;

    private DatabaseQueryLogger $logger;

    private string|null $sqliteFile = null;

    protected function setUp(): void
    {
        $this->logger = new DatabaseQueryLogger();
        $this->connection = $this->createConnection();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $this->connection->executeStatement('DROP VIEW IF EXISTS reset_view');

            foreach (['reset_child', 'reset_parent', 'order'] as $table) {
                $this->connection->executeStatement('DROP TABLE IF EXISTS '.$this->connection->getDatabasePlatform()->quoteSingleIdentifier($table));
            }

            $this->connection->close();
        }

        if (null !== $this->sqliteFile) {
            unlink($this->sqliteFile);
        }
    }

    public function testClearsAllTablesAndRestartsIdentitiesRepeatedly(): void
    {
        $resetter = new DatabaseResetter();

        for ($run = 0; $run < 3; ++$run) {
            $this->populateTables();
            $resetter->reset($this->connection);

            foreach (['reset_child', 'reset_parent', 'order'] as $table) {
                $this->assertSame(0, $this->rowCount($table));
            }

            $this->connection->insert('reset_parent', ['name' => 'After reset']);
            $this->assertSame(1, (int) $this->connection->fetchOne('SELECT id FROM reset_parent'));
            $this->assertSame('After reset', $this->connection->fetchOne('SELECT name FROM reset_view'));
            $resetter->reset($this->connection);
        }
    }

    public function testResetsAnEmptyTableWithAnAdvancedIdentity(): void
    {
        $this->connection->insert('reset_parent', ['name' => 'Deleted']);
        $this->connection->executeStatement('DELETE FROM reset_parent');
        (new DatabaseResetter())->reset($this->connection);
        $this->connection->insert('reset_parent', ['name' => 'After reset']);

        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT id FROM reset_parent'));
    }

    public function testClearsWritesFromAnotherConnection(): void
    {
        $this->assertExternalWritesAreReset();
    }

    public function testRejectsActiveTransactionsWithoutChangingData(): void
    {
        $this->connection->beginTransaction();
        $this->connection->insert('reset_parent', ['name' => 'Keep']);

        try {
            (new DatabaseResetter())->reset($this->connection);
            $this->fail('Expected an active transaction to prevent the reset.');
        } catch (E2eTestException $exception) {
            $this->assertStringContainsString('active transaction', $exception->getMessage());
        }

        $this->assertTrue($this->connection->isTransactionActive());
        $this->assertSame(1, $this->rowCount('reset_parent'));
        $this->connection->rollBack();
        $this->assertSame(0, $this->rowCount('reset_parent'));
    }

    public function testHandlesAnEmptySchema(): void
    {
        $this->connection->executeStatement('DROP VIEW reset_view');

        foreach (['reset_child', 'reset_parent', 'order'] as $table) {
            $this->connection->executeStatement('DROP TABLE '.$this->connection->getDatabasePlatform()->quoteSingleIdentifier($table));
        }

        (new DatabaseResetter())->reset($this->connection);

        $this->assertSame([], $this->connection->createSchemaManager()->listTableNames());
    }

    public function testPreservesForeignKeySettings(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if (!$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform) {
            $this->markTestSkipped('PostgreSQL does not disable foreign keys for resets.');
        }

        $setting = $platform instanceof SQLitePlatform ? 'PRAGMA foreign_keys' : 'SELECT @@SESSION.foreign_key_checks';
        $assignment = $platform instanceof SQLitePlatform ? 'PRAGMA foreign_keys = ' : 'SET FOREIGN_KEY_CHECKS = ';

        foreach ([0, 1] as $enabled) {
            $this->connection->executeStatement($assignment.$enabled);
            $this->populateTables();
            (new DatabaseResetter())->reset($this->connection);

            $this->assertSame($enabled, (int) $this->connection->fetchOne($setting));
        }
    }

    public function testMySqlSkipsCleanTablesAndProbesMoreThanOneChunk(): void
    {
        $this->requireMySql();
        $tables = [];

        try {
            for ($i = 0; $i < 101; ++$i) {
                $table = 'reset_empty_'.$i;
                $this->connection->executeStatement('CREATE TABLE '.$table.' (id INT PRIMARY KEY)');
                $tables[] = $table;
            }

            $this->connection->executeStatement('INSERT INTO reset_empty_99 VALUES (42)');
            $this->logger->clear();
            (new DatabaseResetter())->reset($this->connection);

            $this->assertSame(['TRUNCATE TABLE `reset_empty_99`'], $this->logger->truncations());
            $this->assertSame(0, $this->rowCount('reset_empty_99'));
            $this->logger->clear();
            (new DatabaseResetter())->reset($this->connection);
            $this->assertSame([], $this->logger->truncations());
        } finally {
            foreach ($tables as $table) {
                $this->connection->executeStatement('DROP TABLE '.$table);
            }
        }
    }

    public function testMySqlDetectsRowsWithAnInitialIdentityCounter(): void
    {
        $this->requireMySql();
        $mode = (string) $this->connection->fetchOne('SELECT @@SESSION.sql_mode');
        $this->connection->executeStatement('SET SESSION sql_mode = ?', [trim($mode.',NO_AUTO_VALUE_ON_ZERO', ',')]);

        try {
            $this->connection->insert('reset_parent', ['id' => 0, 'name' => 'Zero']);
            (new DatabaseResetter())->reset($this->connection);

            $this->assertSame(0, $this->rowCount('reset_parent'));
        } finally {
            $this->connection->executeStatement('SET SESSION sql_mode = ?', [$mode]);
        }
    }

    public function testMySqlRefreshesCachedIdentityStatisticsAndRestoresExpiry(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof MySQL80Platform) {
            $this->markTestSkipped('Only MySQL 8 caches these statistics.');
        }

        $expiry = (int) $this->connection->fetchOne('SELECT @@SESSION.information_schema_stats_expiry');
        $this->connection->executeStatement('SET SESSION information_schema_stats_expiry = 86400');

        try {
            $this->connection->fetchAllAssociative('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
            $this->assertExternalWritesAreReset();
            $this->assertSame(86400, (int) $this->connection->fetchOne('SELECT @@SESSION.information_schema_stats_expiry'));
        } finally {
            $this->connection->executeStatement('SET SESSION information_schema_stats_expiry = '.$expiry);
        }
    }

    public function testSqliteRollsBackFailedResetsAndRestoresForeignKeys(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->markTestSkipped('This test uses a SQLite trigger to fail the reset.');
        }

        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
        $this->populateTables();
        $this->connection->executeStatement("CREATE TRIGGER reset_failure BEFORE DELETE ON reset_parent BEGIN SELECT RAISE(ABORT, 'Reset failed'); END");

        try {
            (new DatabaseResetter())->reset($this->connection);
            $this->fail('Expected the trigger to fail the reset.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('Reset failed', $exception->getMessage());
        }

        $this->assertFalse($this->connection->isTransactionActive());
        $this->assertSame(1, (int) $this->connection->fetchOne('PRAGMA foreign_keys'));
        $this->assertSame(1, $this->rowCount('reset_parent'));
        $this->assertSame(1, $this->rowCount('reset_child'));
        $this->assertSame(1, $this->rowCount('order'));
    }

    public function testPostgreSqlResetsTablesInOtherSchemasAndPreservesStandaloneSequences(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->markTestSkipped('This test covers PostgreSQL schemas and sequences.');
        }

        $this->connection->executeStatement('CREATE SCHEMA "reset.extra"');

        try {
            $this->connection->executeStatement('CREATE TABLE "reset.extra"."child.table" (id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, parent_id INT REFERENCES reset_parent (id))');
            $this->connection->executeStatement('CREATE SEQUENCE "reset.extra".standalone');
            $this->populateTables();
            $this->connection->executeStatement('INSERT INTO "reset.extra"."child.table" (parent_id) SELECT id FROM reset_parent');
            $this->connection->fetchOne("SELECT nextval('\"reset.extra\".standalone')");
            (new DatabaseResetter())->reset($this->connection);

            $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM "reset.extra"."child.table"'));
            $this->connection->executeStatement('INSERT INTO "reset.extra"."child.table" (parent_id) VALUES (NULL)');
            $this->assertSame(1, (int) $this->connection->fetchOne('SELECT id FROM "reset.extra"."child.table"'));
            $this->assertSame(2, (int) $this->connection->fetchOne("SELECT nextval('\"reset.extra\".standalone')"));
        } finally {
            $this->connection->executeStatement('DROP SCHEMA "reset.extra" CASCADE');
        }
    }

    public function testRejectsLazyConnectionsWithAutoCommitDisabled(): void
    {
        $connection = DriverManager::getConnection($this->connectionParams);
        $connection->setAutoCommit(false);
        $this->assertFalse($connection->isConnected());

        try {
            try {
                (new DatabaseResetter())->reset($connection);
                $this->fail('Expected disabled auto-commit to prevent the reset.');
            } catch (E2eTestException $exception) {
                $this->assertStringContainsString('auto-commit', $exception->getMessage());
            }

            $this->assertFalse($connection->isConnected());
            $this->assertFalse($connection->isTransactionActive());
        } finally {
            $connection->close();
        }
    }

    public function testSqliteResetsSchemasWithoutAutoIncrementTables(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->markTestSkipped('This test covers a SQLite schema without sqlite_sequence.');
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        try {
            $connection->executeStatement('PRAGMA foreign_keys = ON');
            $connection->executeStatement('CREATE TABLE example (id INTEGER PRIMARY KEY)');
            $connection->insert('example', ['id' => 42]);
            (new DatabaseResetter())->reset($connection);

            $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM example'));
            $this->assertSame(1, (int) $connection->fetchOne('PRAGMA foreign_keys'));
            $this->assertFalse($connection->isTransactionActive());
        } finally {
            $connection->close();
        }
    }

    public function testPostgreSqlRollsBackDataAndIdentitiesWhenATruncateTriggerFails(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->markTestSkipped('This test covers PostgreSQL truncate triggers.');
        }

        $this->populateTables();
        $this->connection->executeStatement("CREATE FUNCTION reset_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Reset failed'; END $$");

        try {
            $this->connection->executeStatement('CREATE TRIGGER reset_failure AFTER TRUNCATE ON reset_parent FOR EACH STATEMENT EXECUTE FUNCTION reset_failure()');

            try {
                (new DatabaseResetter())->reset($this->connection);
                $this->fail('Expected the truncate trigger to fail the reset.');
            } catch (Exception $exception) {
                $this->assertStringContainsString('Reset failed', $exception->getMessage());
            }

            $this->assertFalse($this->connection->isTransactionActive());

            foreach (['reset_parent', 'reset_child', 'order'] as $table) {
                $this->assertSame(1, $this->rowCount($table));
            }

            $this->connection->insert('reset_parent', ['name' => 'After failure']);
            $this->assertSame(2, (int) $this->connection->fetchOne('SELECT MAX(id) FROM reset_parent'));
        } finally {
            $this->connection->executeStatement('DROP FUNCTION reset_failure() CASCADE');
        }
    }

    private function assertExternalWritesAreReset(): void
    {
        $other = DriverManager::getConnection($this->connectionParams);

        try {
            $other->insert('reset_parent', ['name' => 'External']);
            $other->executeStatement('DELETE FROM reset_parent');
            $other->executeStatement('INSERT INTO '.$other->getDatabasePlatform()->quoteSingleIdentifier('order').' (id) VALUES (42)');
            (new DatabaseResetter())->reset($this->connection);
            $this->connection->insert('reset_parent', ['name' => 'After reset']);

            $this->assertSame(0, $this->rowCount('order'));
            $this->assertSame(1, (int) $this->connection->fetchOne('SELECT id FROM reset_parent'));
        } finally {
            $other->close();
        }
    }

    private function createConnection(): Connection
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($this->logger)]);

        $url = getenv('CONTAO_E2E_RESET_DATABASE_URL');

        if (false !== $url && '' !== $url) {
            $params = (new DsnParser(['mysql' => 'pdo_mysql', 'postgres' => 'pdo_pgsql']))->parse($url);
        } else {
            $file = tempnam(sys_get_temp_dir(), 'database-reset-');

            if (false === $file) {
                throw new \RuntimeException('Could not create the SQLite test database.');
            }

            $this->sqliteFile = $file;
            $params = ['driver' => 'pdo_sqlite', 'path' => $file];
        }

        $this->connectionParams = $params;

        return DriverManager::getConnection($params, $configuration);
    }

    private function createTables(): void
    {
        $schema = $this->connection->createSchemaManager()->introspectSchema();
        $parent = $schema->createTable('reset_parent');
        $parent->addColumn('id', 'integer', ['autoincrement' => true]);
        $parent->addColumn('name', 'string', ['length' => 100]);
        $parent->addColumn('parent_id', 'integer', ['notnull' => false]);
        $parent->setPrimaryKey(['id']);
        $parent->addForeignKeyConstraint('reset_parent', ['parent_id'], ['id']);

        $child = $schema->createTable('reset_child');
        $child->addColumn('id', 'integer');
        $child->addColumn('parent_id', 'integer');
        $child->setPrimaryKey(['id']);
        $child->addForeignKeyConstraint('reset_parent', ['parent_id'], ['id']);

        $plain = $schema->createTable('`order`');
        $plain->addColumn('id', 'integer');
        $plain->setPrimaryKey(['id']);

        foreach ($schema->getTables() as $table) {
            foreach ($this->connection->getDatabasePlatform()->getCreateTableSQL($table) as $sql) {
                $this->connection->executeStatement($sql);
            }
        }

        $this->connection->executeStatement('CREATE VIEW reset_view AS SELECT name FROM reset_parent');
    }

    private function populateTables(): void
    {
        $this->connection->insert('reset_parent', ['name' => 'Initial']);
        $id = (int) $this->connection->fetchOne('SELECT MAX(id) FROM reset_parent');
        $this->connection->executeStatement('UPDATE reset_parent SET parent_id = id');
        $this->connection->insert('reset_child', ['id' => 1, 'parent_id' => $id]);
        $this->connection->executeStatement('INSERT INTO '.$this->connection->getDatabasePlatform()->quoteSingleIdentifier('order').' (id) VALUES (42)');
    }

    private function rowCount(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$this->connection->getDatabasePlatform()->quoteSingleIdentifier($table));
    }

    private function requireMySql(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->markTestSkipped('This test covers the MySQL/MariaDB optimization.');
        }
    }
}
