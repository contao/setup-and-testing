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

use Contao\E2eTesting\Application\ApplicationRuntime;
use Contao\E2eTesting\Browser\BrowserRuntime;
use Contao\E2eTesting\Browser\BrowserSession;
use Contao\E2eTesting\Browser\BrowserSessionFactoryInterface;
use Contao\E2eTesting\Cache\FingerprintSet;
use Contao\E2eTesting\Database\DatabaseManager;
use Contao\E2eTesting\Database\DatabaseServerConfig;
use Contao\E2eTesting\Http\ServerManager;
use Contao\E2eTesting\Installation\InstallationLease;
use Contao\E2eTesting\Installation\PreparedInstallation;
use Contao\E2eTesting\ManagedEdition\ManagedEdition;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\E2eTesting\ManagedEdition\ManagedEditionState;
use Contao\E2eTesting\Process\ContaoConsole;
use Contao\E2eTesting\Process\ProcessRunner;
use Contao\InstallationRecipe\Cache\InMemoryCache;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Exception\InvalidRecipeException;
use Contao\InstallationRecipe\Fixture\FixtureLoader;
use Contao\InstallationRecipe\Fixture\FixtureParser;
use Contao\InstallationRecipe\Fixture\FixtureSet;
use Contao\InstallationRecipe\Fixture\FixtureValueResolver;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Playwright\Browser\BrowserContextInterface;
use Playwright\Page\PageInterface;
use Symfony\Component\Filesystem\Filesystem;

final class ManagedEditionFixtureTest extends TestCase
{
    private string $directory;

    private string $fixture;

    private DatabaseManager $database;

    private ManagedEdition $application;

    private int $databaseOperations = 0;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/managed-fixtures-'.bin2hex(random_bytes(6));
        $this->fixture = $this->directory.'/fixtures.yaml';
        (new Filesystem())->dumpFile($this->fixture, "example:\n  article: {title: Initial}\n");
        $this->database = $this->database();
        $this->application = $this->application();
    }

    protected function tearDown(): void
    {
        $this->application->release();
        (new Filesystem())->remove($this->directory);
    }

    public function testReleaseClosesDatabaseAndLeaseWhenContextCleanupFails(): void
    {
        $lock = fopen($this->directory.'/lease.lock', 'c+');
        $this->assertIsResource($lock);
        $installation = new PreparedInstallation(new InstallationLease($this->directory.'/installation', 0, $lock), $this->database, new FingerprintSet('fixtures', 'fixtures', 'fixtures'));
        $context = $this->createStub(BrowserContextInterface::class);
        $failure = new \RuntimeException('Context cleanup failed');
        $context
            ->method('close')
            ->willThrowException($failure)
        ;
        $factory = $this->createStub(BrowserSessionFactoryInterface::class);
        $factory
            ->method('create')
            ->willReturn(new BrowserSession('https://example.test', $context, $this->createStub(PageInterface::class)))
        ;
        $runtime = new ApplicationRuntime(new InMemoryCache(), $factory);
        $browserRuntime = $runtime->createBrowserRuntime($this->directory.'/traces');
        $application = $this->createApplication($installation, $runtime, $browserRuntime);
        $browserRuntime->createBrowser('https://example.test');

        try {
            try {
                $application->release();
                $this->fail('Expected context cleanup to fail.');
            } catch (\RuntimeException $exception) {
                $this->assertSame($failure, $exception);
            }

            $this->assertFalse(\is_resource($lock));
            $this->assertNull((new \ReflectionProperty(DatabaseManager::class, 'connection'))->getValue($this->database));
        } finally {
            $application->release();
            $runtime->close();
        }
    }

    public function testExposesTheInitialInstallationLoadWithoutDatabaseAccess(): void
    {
        $initial = $this->database->reset(new FixtureSet([$this->fixture]));
        $operations = $this->databaseOperations;

        $this->assertSame($initial, $this->application->database()->fixtures());
        $this->assertSame('1', $this->application->database()->fixtures()->value('article'));
        $this->assertSame('/articles/1/Initial', $this->application->database()->fixtures()->interpolate('/articles/{article}/{article->title}'));
        $this->assertSame($operations, $this->databaseOperations);
    }

    public function testDatabaseResetsReplaceTheCurrentResult(): void
    {
        $initial = $this->application->resetDatabase();
        $current = $this->application->resetDatabase();

        $this->assertNotSame($initial, $current);
        $this->assertSame($current, $this->application->database()->fixtures());
        $this->assertSame('2', $current->value('article'));
    }

    public function testPreparedFixturesRemainCurrentDuringReuseAndRuntimeResets(): void
    {
        $fixtures = new FixtureSet([$this->fixture]);
        $initial = $this->application->prepareDatabase($fixtures);
        $operations = $this->databaseOperations;
        $this->application->resetRuntime();

        $this->assertSame($initial, $this->application->database()->fixtures());
        $this->assertSame($initial, $this->application->prepareDatabase($fixtures));
        $this->assertSame($initial, $this->application->database()->fixtures());
        $this->assertSame($operations, $this->databaseOperations);
        (new Filesystem())->dumpFile($this->fixture, "example:\n  article: {title: Changed}\n");
        $changed = $this->application->prepareDatabase($fixtures);

        $this->assertNotSame($initial, $changed);
        $this->assertSame($changed, $this->application->database()->fixtures());
        $this->assertSame('Changed', $changed->value('article', 'title'));
    }

    public function testDirectDatabaseResetCannotReuseThePreviousPreparedResult(): void
    {
        $fixtures = new FixtureSet([$this->fixture]);
        $initial = $this->application->prepareDatabase($fixtures);
        $direct = $this->database->reset($fixtures);
        $this->assertSame($direct, $this->database->fixtures());
        $prepared = $this->application->prepareDatabase($fixtures);

        $this->assertNotSame($initial, $prepared);
        $this->assertNotSame($direct, $prepared);
        $this->assertSame($prepared, $this->database->fixtures());
        $this->assertSame('3', $prepared->value('article'));
    }

    public function testDirectLoadingOfDifferentFixturesRestoresTheRequestedSet(): void
    {
        $fixtures = new FixtureSet([$this->fixture]);
        $initial = $this->application->prepareDatabase($fixtures);
        $custom = $this->directory.'/custom.yaml';
        (new Filesystem())->dumpFile($custom, "example:\n  custom: {title: Custom}\n");
        $this->database->reset(new FixtureSet([$custom]));
        $operations = $this->databaseOperations;
        $prepared = $this->application->prepareDatabase($fixtures);

        $this->assertNotSame($initial, $prepared);
        $this->assertSame($prepared, $this->database->fixtures());
        $this->assertSame('Initial', $prepared->value('article', 'title'));
        $this->assertGreaterThan($operations, $this->databaseOperations);
    }

    public function testFailedDirectDatabaseResetCannotReuseThePreviousResult(): void
    {
        $fixtures = new FixtureSet([$this->fixture]);
        $initial = $this->application->prepareDatabase($fixtures);
        $invalid = $this->directory.'/invalid.yaml';
        (new Filesystem())->dumpFile($invalid, "example:\n  article: {title: '@missing'}\n");

        try {
            $this->database->reset(new FixtureSet([$invalid]));
            $this->fail('Expected unresolved fixture references to fail.');
        } catch (InvalidRecipeException $exception) {
            $this->assertStringContainsString('missing', $exception->getMessage());
        }

        $this->assertFalse($this->database->hasFixtures());
        $prepared = $this->application->prepareDatabase($fixtures);

        $this->assertNotSame($initial, $prepared);
        $this->assertSame($prepared, $this->database->fixtures());
        $this->assertSame('Initial', $prepared->value('article', 'title'));
    }

    public function testResetStateRestoresTheConfiguredFixtures(): void
    {
        $custom = $this->directory.'/custom.yaml';
        (new Filesystem())->dumpFile($custom, "example:\n  custom: {title: Custom}\n");
        $result = $this->application->prepareDatabase(new FixtureSet([$custom]));
        $this->assertSame('Custom', $this->application->database()->fixtures()->value('custom', 'title'));
        $this->application->resetState();

        $this->assertNotSame($result, $this->application->database()->fixtures());
        $this->assertSame('Initial', $this->application->database()->fixtures()->value('article', 'title'));
    }

    public function testEmptyFixtureLoadsHaveAnAvailableResult(): void
    {
        $result = $this->application->resetDatabase(FixtureSet::empty());

        $this->assertSame($result, $this->application->database()->fixtures());
        $this->assertSame('/articles', $result->interpolate('/articles'));
    }

    public function testResultIsUnavailableBeforeFixturesHaveBeenLoaded(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No fixtures have been loaded');
        $this->application->database()->fixtures();
    }

    public function testFailedFixtureLoadDiscardsThePreviousResult(): void
    {
        $this->application->resetDatabase();
        (new Filesystem())->dumpFile($this->fixture, "example:\n  article: {title: '@missing'}\n");

        try {
            $this->application->resetDatabase();
            $this->fail('Expected unresolved fixture references to fail.');
        } catch (InvalidRecipeException $exception) {
            $this->assertStringContainsString('missing', $exception->getMessage());
        }

        $this->expectException(\LogicException::class);
        $this->application->database()->fixtures();
    }

    private function database(): DatabaseManager
    {
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $sqlite->executeStatement('CREATE TABLE example (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)');

        $connection = $this->fixtureConnection($sqlite);
        $this->configureReset($connection, $sqlite);
        $cache = new InMemoryCache();
        $loader = new FixtureLoader(new FixtureParser($cache), new FixtureValueResolver(), $cache);
        $database = new DatabaseManager(new DatabaseServerConfig('mysql://localhost'), 'unused', $loader);
        (new \ReflectionProperty(DatabaseManager::class, 'connection'))->setValue($database, $connection);

        return $database;
    }

    private function fixtureConnection(Connection $sqlite): Connection&Stub
    {
        $connection = $this->createStub(Connection::class);
        $connection
            ->method('getDatabasePlatform')
            ->willReturn($sqlite->getDatabasePlatform())
        ;

        $connection
            ->method('createSchemaManager')
            ->willReturn($sqlite->createSchemaManager())
        ;

        $connection
            ->method('quoteSingleIdentifier')
            ->willReturnCallback($sqlite->quoteSingleIdentifier(...))
        ;

        $connection
            ->method('insert')
            ->willReturnCallback($sqlite->insert(...))
        ;

        $connection
            ->method('lastInsertId')
            ->willReturnCallback($sqlite->lastInsertId(...))
        ;

        return $connection;
    }

    private function configureReset(Connection&Stub $connection, Connection $sqlite): void
    {
        $connection
            ->method('executeStatement')
            ->willReturnCallback(
                function (string $sql) use ($sqlite): int {
                    ++$this->databaseOperations;

                    return str_starts_with($sql, 'TRUNCATE TABLE ') ? $sqlite->executeStatement(str_replace('TRUNCATE TABLE ', 'DELETE FROM ', $sql)) : 0;
                },
            )
        ;
        $connection
            ->method('transactional')
            ->willReturnCallback(
                function (callable $operation) use ($sqlite): mixed {
                    ++$this->databaseOperations;

                    return $sqlite->transactional(static fn (): mixed => $operation());
                },
            )
        ;
    }

    private function application(): ManagedEdition
    {
        $installation = new PreparedInstallation(
            new InstallationLease($this->directory.'/installation', 0, null),
            $this->database,
            new FingerprintSet('fixtures', 'fixtures', 'fixtures'),
        );
        $runtime = ApplicationRuntime::create();

        return $this->createApplication($installation, $runtime, $runtime->createBrowserRuntime($this->directory.'/traces'));
    }

    private function createApplication(PreparedInstallation $installation, ApplicationRuntime $runtime, BrowserRuntime $browserRuntime): ManagedEdition
    {
        $recipe = InstallationRecipe::create(ComposerConfig::managedEdition('^5.7'))->withFixtureFile($this->fixture);

        return new ManagedEdition(
            new ManagedEditionState($installation, ManagedEditionConfig::create($recipe, $this->directory), new ContaoConsole(new ProcessRunner())),
            new ServerManager(),
            $browserRuntime,
            $runtime,
        );
    }
}
