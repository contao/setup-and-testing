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
use Contao\InstallationRecipe\Exception\InvalidRecipeException;
use Contao\InstallationRecipe\Fixture\FixtureLoader;
use Contao\InstallationRecipe\Fixture\FixtureParser;
use Contao\InstallationRecipe\Fixture\FixtureSet;
use Contao\InstallationRecipe\Fixture\FixtureValueResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class FixtureLoaderTest extends TestCase
{
    public function testLoadsRows(): void
    {
        $fixture = $this->fixture("example:\n  - id: 1\n    title: Hello\n");
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE example (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');

        $this->fixtureLoader()->load($connection, new FixtureSet([$fixture]));

        $this->assertSame([['id' => 1, 'title' => 'Hello']], $connection->fetchAllAssociative('SELECT * FROM example'));
    }

    public function testResolvesReferencesToGeneratedIdentifiers(): void
    {
        $fixture = $this->fixture(<<<'YAML'
            example:
              child:
                parent_id: '@parent'
                title: Child
              parent:
                parent_id: 0
                title: Parent
            YAML);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE example (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER NOT NULL, title TEXT NOT NULL)');
        $connection->insert('example', ['parent_id' => 0, 'title' => 'Existing row']);

        $result = $this->fixtureLoader()->load($connection, new FixtureSet([$fixture]));

        $this->assertSame(2, (int) $result->value('parent'));
        $this->assertSame(3, (int) $result->value('child'));
        $this->assertSame('/pages/2/Parent', $result->interpolate('/pages/{parent}/{parent->title}'));
        $this->assertSame(
            [
                ['id' => 1, 'parent_id' => 0, 'title' => 'Existing row'],
                ['id' => 2, 'parent_id' => 0, 'title' => 'Parent'],
                ['id' => 3, 'parent_id' => 2, 'title' => 'Child'],
            ],
            $connection->fetchAllAssociative('SELECT * FROM example ORDER BY id'),
        );
    }

    public function testResolvesCrossFileReferencesAndColumns(): void
    {
        $dependentFixture = $this->fixture(<<<'YAML'
            dependent:
              dependent:
                source_id: '@source'
                label: '@source->label'
            YAML);
        $sourceFixture = $this->fixture(<<<'YAML'
            source:
              source:
                label: Source
            YAML);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE source (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL)');
        $connection->executeStatement('CREATE TABLE dependent (id INTEGER PRIMARY KEY AUTOINCREMENT, source_id INTEGER NOT NULL, label TEXT NOT NULL)');

        $this->fixtureLoader()->load($connection, new FixtureSet([$dependentFixture, $sourceFixture]));

        $this->assertSame(
            [['id' => 1, 'source_id' => 1, 'label' => 'Source']],
            $connection->fetchAllAssociative('SELECT * FROM dependent'),
        );
    }

    public function testEscapesLiteralAtSigns(): void
    {
        $fixture = $this->fixture("example:\n  row:\n    value: '\\@literal'\n");
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE example (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL)');

        $this->fixtureLoader()->load($connection, new FixtureSet([$fixture]));

        $this->assertSame('@literal', $connection->fetchOne('SELECT value FROM example'));
    }

    public function testResolvesReferencesInSerializedLists(): void
    {
        $fixture = $this->fixture(<<<'YAML'
            example:
              parent:
                related: []
              child:
                related:
                  - '@parent'
                  - literal
            YAML);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE example (id INTEGER PRIMARY KEY AUTOINCREMENT, related TEXT NOT NULL)');

        $this->fixtureLoader()->load($connection, new FixtureSet([$fixture]));

        $this->assertSame(['1', 'literal'], unserialize($connection->fetchOne('SELECT related FROM example WHERE id = 2')));
    }

    public function testResolvesReferencesInJsonValues(): void
    {
        $fixture = $this->fixture(<<<'YAML'
            example:
              parent:
                options: []
              child:
                options: !json
                  parent: '@parent'
                  enabled: true
                  values:
                    - first
                    - second
            YAML);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE example (id INTEGER PRIMARY KEY AUTOINCREMENT, options TEXT NOT NULL)');

        $this->fixtureLoader()->load($connection, new FixtureSet([$fixture]));

        $this->assertSame(
            ['parent' => '1', 'enabled' => true, 'values' => ['first', 'second']],
            json_decode((string) $connection->fetchOne('SELECT options FROM example WHERE id = 2'), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testRejectsUnknownValueTags(): void
    {
        $fixture = $this->fixture("example:\n  row:\n    value: !xml '<value />'\n");
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('The fixture value tag "!xml"');

        $this->fixtureLoader()->load($connection, new FixtureSet([$fixture]));
    }

    public function testRollsBackUnresolvableReferences(): void
    {
        $fixture = $this->fixture(<<<'YAML'
            example:
              independent:
                parent_id: 0
              dependent:
                parent_id: '@missing'
            YAML);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE example (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER NOT NULL)');

        try {
            $this->fixtureLoader()->load($connection, new FixtureSet([$fixture]));
            $this->fail('Expected the unresolved fixture reference to throw an exception.');
        } catch (InvalidRecipeException $exception) {
            $this->assertSame('Fixture dependencies cannot be resolved: missing.', $exception->getMessage());
        }

        $this->assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM example'));
    }

    public function testRejectsCircularReferences(): void
    {
        $fixture = $this->fixture(<<<'YAML'
            example:
              first:
                parent_id: '@second'
              second:
                parent_id: '@first'
            YAML);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE example (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER NOT NULL)');

        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('Fixture dependencies cannot be resolved: second, first.');

        $this->fixtureLoader()->load($connection, new FixtureSet([$fixture]));
    }

    public function testRejectsDuplicateFixtureNames(): void
    {
        $firstFixture = $this->fixture("example:\n  duplicate:\n    value: First\n");
        $secondFixture = $this->fixture("example:\n  duplicate:\n    value: Second\n");
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('The fixture name "duplicate" is defined more than once.');

        $this->fixtureLoader()->load($connection, new FixtureSet([$firstFixture, $secondFixture]));
    }

    public function testRejectsUnknownFixturesWhileInterpolating(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $result = $this->fixtureLoader()->load($connection, FixtureSet::empty());

        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('The fixture "missing" does not exist.');

        $result->interpolate('/pages/{missing}');
    }

    public function testRejectsSqlEntries(): void
    {
        $fixture = $this->fixture("sql:\n  - DROP TABLE example\n");

        $this->expectException(InvalidRecipeException::class);
        $this->fixtureLoader()->load(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), new FixtureSet([$fixture]));
    }

    public function testRepeatedLoadsResolveFreshIdentitiesAndStructuredReferences(): void
    {
        $file = $this->fixture(<<<'YAML'
            example:
              child:
                parent_id: '@parent'
                options: !json {parent: '@parent', literal: '\@literal'}
                related: ['@parent']
              parent:
                parent_id: 0
                options: !json {}
                related: []
            YAML);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE example (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER, options TEXT, related TEXT)');

        $loader = $this->fixtureLoader();
        $fixtures = new FixtureSet([$file]);

        foreach ([1, 3] as $expectedParent) {
            $result = $loader->load($connection, $fixtures);
            $this->assertSame($expectedParent, (int) $result->value('parent'));
            $row = $connection->fetchAssociative('SELECT * FROM example WHERE id = ?', [$result->value('child')]);
            $this->assertIsArray($row);
            $this->assertSame($expectedParent, (int) $row['parent_id']);
            $this->assertSame(['parent' => (string) $expectedParent, 'literal' => '@literal'], json_decode($row['options'], true));
            $this->assertSame([(string) $expectedParent], unserialize($row['related']));
        }
    }

    public function testExplicitSchemaInvalidationRefreshesTheIdentityColumn(): void
    {
        $file = $this->fixture("example:\n  row: {title: Example}\n");
        $fixtures = new FixtureSet([$file]);
        $loader = $this->fixtureLoader();
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE example (old_id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)');
        $this->assertSame('1', $loader->load($connection, $fixtures)->value('row', 'old_id'));
        $connection->executeStatement('DROP TABLE example');
        $connection->executeStatement('CREATE TABLE example (new_id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)');

        $loader->invalidateCache($connection);

        $this->assertSame('1', $loader->load($connection, $fixtures)->value('row', 'new_id'));
    }

    public function testCurrentResultsAreRetainedSeparatelyForEachConnection(): void
    {
        $loader = $this->fixtureLoader();
        $first = $this->fixtureConnection();
        $second = $this->fixtureConnection();
        $second->insert('example', ['title' => 'Existing']);

        $fixtures = new FixtureSet([$this->fixture("example:\n  article: {title: Article}\n")]);
        $firstResult = $loader->load($first, $fixtures);
        $secondResult = $loader->load($second, $fixtures);

        $this->assertSame($firstResult, $loader->result($first));
        $this->assertTrue($loader->hasResult($first));
        $this->assertSame($secondResult, $loader->result($second));
        $this->assertSame('1', $loader->result($first)->value('article'));
        $this->assertSame('2', $loader->result($second)->value('article'));
        $replacement = $loader->load($first, $fixtures);

        $this->assertNotSame($firstResult, $replacement);
        $this->assertSame($replacement, $loader->result($first));
        $this->assertSame($secondResult, $loader->result($second));
    }

    public function testInvalidationDiscardsOnlyTheAffectedConnectionsResult(): void
    {
        $loader = $this->fixtureLoader();
        $first = $this->fixtureConnection();
        $second = $this->fixtureConnection();
        $loader->load($first, FixtureSet::empty());
        $secondResult = $loader->load($second, FixtureSet::empty());
        $loader->invalidateCache($first);

        $this->assertFalse($loader->hasResult($first));
        $this->assertTrue($loader->hasResult($second));
        $this->assertSame($secondResult, $loader->result($second));
        $this->expectException(\LogicException::class);
        $loader->result($first);
    }

    public function testFailedParsingDiscardsTheConnectionsPreviousResult(): void
    {
        $loader = $this->fixtureLoader();
        $connection = $this->fixtureConnection();
        $file = $this->fixture("example:\n  article: {title: Article}\n");
        $loader->load($connection, new FixtureSet([$file]));
        $duplicate = $this->fixture("example:\n  article: {title: Duplicate}\n");

        try {
            $loader->load($connection, new FixtureSet([$file, $duplicate]));
            $this->fail('Expected duplicate fixture names to fail.');
        } catch (InvalidRecipeException $exception) {
            $this->assertStringContainsString('defined more than once', $exception->getMessage());
        }

        $this->expectException(\LogicException::class);
        $loader->result($connection);
    }

    public function testRetainedResultsDoNotKeepConnectionsAlive(): void
    {
        $loader = $this->fixtureLoader();
        $connection = $this->fixtureConnection();
        $result = $loader->load($connection, FixtureSet::empty());
        $connectionReference = \WeakReference::create($connection);
        $resultReference = \WeakReference::create($result);
        unset($connection, $result);
        gc_collect_cycles();

        $this->assertNull($connectionReference->get());
        $this->assertNull($resultReference->get());
    }

    public function testUnloadedConnectionsHaveNoCurrentResult(): void
    {
        $loader = $this->fixtureLoader();
        $connection = $this->fixtureConnection();
        $this->assertFalse($loader->hasResult($connection));
        $this->expectException(\LogicException::class);
        $loader->result($connection);
    }

    private function fixtureConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE example (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)');

        return $connection;
    }

    private function fixture(string $contents): string
    {
        $directory = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests';
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory);

        $path = $filesystem->tempnam($directory, 'fixture-');
        $filesystem->dumpFile($path, $contents);

        return $path;
    }

    private function fixtureLoader(): FixtureLoader
    {
        $cache = new InMemoryCache();

        return new FixtureLoader(new FixtureParser($cache), new FixtureValueResolver(), $cache);
    }
}
