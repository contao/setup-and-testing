<?php

declare(strict_types=1);

namespace Contao\E2eTestBundle\Database;

use Contao\InstallationRecipe\Fixture\FixtureLoader;
use Contao\InstallationRecipe\Fixture\FixtureResult;
use Contao\InstallationRecipe\Fixture\FixtureSet;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * @phpstan-import-type Params from DriverManager
 */
final class DatabaseManager
{
    private Connection|null $connection = null;

    private readonly string $applicationUrl;

    public function __construct(
        private readonly DatabaseServerConfig $config,
        private readonly string $databaseName,
        private readonly FixtureLoader $fixtureLoader = new FixtureLoader(),
    ) {
        $this->applicationUrl = DatabaseUrl::parse($config->url)->withDatabase($databaseName);
    }

    public function applicationUrl(): string
    {
        return $this->applicationUrl;
    }

    public function connection(): Connection
    {
        return $this->connection ??= DriverManager::getConnection($this->connectionParameters($this->applicationUrl));
    }

    public function create(): void
    {
        $connection = DriverManager::getConnection($this->connectionParameters($this->config->url));
        $platform = $connection->getDatabasePlatform();
        $connection->executeStatement('CREATE DATABASE IF NOT EXISTS '.$platform->quoteSingleIdentifier($this->databaseName));
        $connection->close();
    }

    public function recreate(): void
    {
        $this->close();
        $connection = DriverManager::getConnection($this->connectionParameters($this->config->url));
        $database = $connection->getDatabasePlatform()->quoteSingleIdentifier($this->databaseName);
        $connection->executeStatement('DROP DATABASE IF EXISTS '.$database);
        $connection->executeStatement('CREATE DATABASE '.$database);
        $connection->close();
    }

    public function reset(FixtureSet $fixtures): FixtureResult
    {
        $connection = $this->connection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($connection->createSchemaManager()->listTableNames() as $table) {
                $connection->executeStatement('TRUNCATE TABLE '.$connection->quoteSingleIdentifier($table));
            }
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $this->fixtureLoader->load($connection, $fixtures);
    }

    public function hasSchema(): bool
    {
        try {
            return [] !== $this->connection()->createSchemaManager()->listTableNames();
        } catch (\Throwable) {
            return false;
        }
    }

    public function close(): void
    {
        $this->connection?->close();
        $this->connection = null;
    }

    /**
     * @phpstan-return Params
     */
    private function connectionParameters(string $url): array
    {
        return new DsnParser([
            'mysql' => 'pdo_mysql',
            'pdo-mysql' => 'pdo_mysql',
            'mysqli' => 'mysqli',
        ])->parse($url);
    }
}
