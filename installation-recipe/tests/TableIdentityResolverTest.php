<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\Tests;

use Contao\InstallationRecipe\Cache\InMemoryCache;
use Contao\InstallationRecipe\Fixture\FixtureDefinition;
use Contao\InstallationRecipe\Fixture\FixtureSource;
use Contao\InstallationRecipe\Fixture\TableIdentityResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class TableIdentityResolverTest extends TestCase
{
    private InMemoryCache $cache;

    protected function setUp(): void
    {
        $this->cache = new InMemoryCache();
    }

    public function testReusesMetadataAcrossResolversButReadsEachGeneratedIdentity(): void
    {
        $connection = $this->connection('id');
        $connection
            ->expects($this->exactly(2))
            ->method('lastInsertId')
            ->willReturn('10', '11')
        ;
        $definition = $this->definition();
        $first = new TableIdentityResolver($connection, $this->cache);
        $second = new TableIdentityResolver($connection, $this->cache);
        $first->prepare($definition);
        $second->prepare($definition);

        $this->assertSame('10', $first->resolve($definition, [])->value());
        $this->assertSame('11', $second->resolve($definition, [])->value());
    }

    public function testCachesTablesWithoutAnIdentityColumn(): void
    {
        $connection = $this->connection(null);
        $connection
            ->expects($this->never())
            ->method('lastInsertId')
        ;
        $definition = $this->definition();
        (new TableIdentityResolver($connection, $this->cache))->prepare($definition);
        $identity = (new TableIdentityResolver($connection, $this->cache))->resolve($definition, ['code' => 'explicit']);

        $this->assertSame('explicit', $identity->value('code'));
    }

    public function testKeepsDifferentDatabaseSchemasSeparate(): void
    {
        $first = $this->connection('id');
        $second = $this->connection('other_id');
        $first
            ->method('lastInsertId')
            ->willReturn('10')
        ;

        $second
            ->method('lastInsertId')
            ->willReturn('20')
        ;

        $this->assertSame('10', (new TableIdentityResolver($first, $this->cache))->resolve($this->definition(), [])->value('id'));
        $this->assertSame('20', (new TableIdentityResolver($second, $this->cache))->resolve($this->definition(), [])->value('other_id'));
    }

    public function testInvalidationIsScopedToOneConnectionAndAlsoClearsMissingIdentities(): void
    {
        $changed = $this->createMock(Connection::class);
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema
            ->expects($this->exactly(2))
            ->method('listTableColumns')
            ->willReturn([], $this->columns('new_id'))
        ;

        $changed
            ->expects($this->exactly(2))
            ->method('createSchemaManager')
            ->willReturn($schema)
        ;

        $changed
            ->method('lastInsertId')
            ->willReturn('1')
        ;
        $unchanged = $this->connection('id');
        $unchanged
            ->method('lastInsertId')
            ->willReturn('2')
        ;
        (new TableIdentityResolver($changed, $this->cache))->prepare($this->definition());
        (new TableIdentityResolver($unchanged, $this->cache))->prepare($this->definition());

        $this->cache->scope($changed)->clear();

        $this->assertSame('1', (new TableIdentityResolver($changed, $this->cache))->resolve($this->definition(), [])->value('new_id'));
        $this->assertSame('2', (new TableIdentityResolver($unchanged, $this->cache))->resolve($this->definition(), [])->value('id'));
    }

    public function testIndependentCachesDoNotShareStateForTheSameConnection(): void
    {
        $connection = $this->createMock(Connection::class);
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema
            ->expects($this->exactly(2))
            ->method('listTableColumns')
            ->willReturn($this->columns('id'), $this->columns('new_id'))
        ;

        $connection
            ->expects($this->exactly(2))
            ->method('createSchemaManager')
            ->willReturn($schema)
        ;

        $connection
            ->method('lastInsertId')
            ->willReturn('1')
        ;
        $first = new TableIdentityResolver($connection, $this->cache);
        $second = new TableIdentityResolver($connection, new InMemoryCache());

        $this->assertSame('1', $first->resolve($this->definition(), [])->value('id'));
        $this->assertSame('1', $second->resolve($this->definition(), [])->value('new_id'));
        $this->assertSame('1', $first->resolve($this->definition(), [])->value('id'));
    }

    public function testCacheDoesNotKeepDiscardedConnectionsAlive(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE example (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        (new TableIdentityResolver($connection, $this->cache))->prepare($this->definition());
        $reference = \WeakReference::create($connection);
        unset($connection);
        gc_collect_cycles();

        $this->assertNull($reference->get());
    }

    private function connection(string|null $column): Connection&MockObject
    {
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema
            ->expects($this->once())
            ->method('listTableColumns')
            ->with('example')
            ->willReturn($this->columns($column))
        ;
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('createSchemaManager')
            ->willReturn($schema)
        ;

        return $connection;
    }

    /**
     * @return array<string, Column>
     */
    private function columns(string|null $column): array
    {
        if (null === $column) {
            return [];
        }

        $cache = $this->createStub(Column::class);
        $cache
            ->method('getAutoincrement')
            ->willReturn(true)
        ;

        return [$column => $cache];
    }

    private function definition(): FixtureDefinition
    {
        return new FixtureDefinition(new FixtureSource('fixture.yaml', 'example'), 'row', []);
    }
}
