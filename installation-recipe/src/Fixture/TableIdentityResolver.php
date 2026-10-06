<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\Fixture;

use Contao\InstallationRecipe\Cache\InMemoryCache;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;

final readonly class TableIdentityResolver
{
    public function __construct(
        private Connection $connection,
        private InMemoryCache $cache,
    ) {
    }

    public function prepare(FixtureDefinition $definition): void
    {
        $this->identityColumn($definition->source->table);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function resolve(FixtureDefinition $definition, array $data): FixtureIdentity
    {
        $column = $this->identityColumn($definition->source->table);

        if (null !== $column && !\array_key_exists($column, $data)) {
            $data[$column] = $this->connection->lastInsertId();
        }

        return new FixtureIdentity($column, $data);
    }

    private function identityColumn(string $tableName): string|null
    {
        $cache = $this->cache->scope($this->connection);
        $key = 'fixture.identity:'.$tableName;

        if ($cache->has($key)) {
            $column = $cache->get($key);

            return \is_string($column) ? $column : null;
        }

        $columns = $this->connection->createSchemaManager()->listTableColumns($tableName);
        $column = $this->findIdentityColumn($columns);
        $cache->set($key, $column);

        return $column;
    }

    /**
     * @param array<string, Column> $columns
     */
    private function findIdentityColumn(array $columns): string|null
    {
        foreach ($columns as $name => $column) {
            if ($column->getAutoincrement()) {
                return $name;
            }
        }

        return null;
    }
}
