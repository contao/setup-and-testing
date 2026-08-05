<?php

declare(strict_types=1);

namespace Contao\InstallationRecipe\Fixture;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;

final class TableIdentityResolver
{
    /**
     * @var array<string, string|null>
     */
    private array $identityColumns = [];

    public function __construct(private readonly Connection $connection)
    {
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
        if (\array_key_exists($tableName, $this->identityColumns)) {
            return $this->identityColumns[$tableName];
        }

        $columns = $this->connection->createSchemaManager()->listTableColumns($tableName);

        return $this->identityColumns[$tableName] = $this->findIdentityColumn($columns);
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
