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

use Contao\InstallationRecipe\Exception\InvalidRecipeException;
use Contao\InstallationRecipe\Fixture\FixtureLoader;
use Contao\InstallationRecipe\Fixture\FixtureSet;
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

        (new FixtureLoader())->load($connection, new FixtureSet([$fixture]));

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

        $result = (new FixtureLoader())->load($connection, new FixtureSet([$fixture]));

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

        (new FixtureLoader())->load($connection, new FixtureSet([$dependentFixture, $sourceFixture]));

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

        (new FixtureLoader())->load($connection, new FixtureSet([$fixture]));

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

        (new FixtureLoader())->load($connection, new FixtureSet([$fixture]));

        $this->assertSame(['1', 'literal'], unserialize($connection->fetchOne('SELECT related FROM example WHERE id = 2')));
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
            (new FixtureLoader())->load($connection, new FixtureSet([$fixture]));
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

        (new FixtureLoader())->load($connection, new FixtureSet([$fixture]));
    }

    public function testRejectsDuplicateFixtureNames(): void
    {
        $firstFixture = $this->fixture("example:\n  duplicate:\n    value: First\n");
        $secondFixture = $this->fixture("example:\n  duplicate:\n    value: Second\n");
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('The fixture name "duplicate" is defined more than once.');

        (new FixtureLoader())->load($connection, new FixtureSet([$firstFixture, $secondFixture]));
    }

    public function testRejectsUnknownFixturesWhileInterpolating(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $result = (new FixtureLoader())->load($connection, FixtureSet::empty());

        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('The fixture "missing" does not exist.');

        $result->interpolate('/pages/{missing}');
    }

    public function testRejectsSqlEntries(): void
    {
        $fixture = $this->fixture("sql:\n  - DROP TABLE example\n");

        $this->expectException(InvalidRecipeException::class);
        (new FixtureLoader())->load(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), new FixtureSet([$fixture]));
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
}
