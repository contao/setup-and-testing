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

use Contao\E2eTesting\Cache\FingerprintSet;
use Contao\E2eTesting\Composer\ComposerInstaller;
use Contao\E2eTesting\Database\InstallationDatabaseInterface;
use Contao\E2eTesting\Exception\ProcessFailedException;
use Contao\E2eTesting\Installation\ApplicationPreparer;
use Contao\E2eTesting\Installation\InstallationBuilder;
use Contao\E2eTesting\Installation\InstallationManifest;
use Contao\E2eTesting\Installation\InstallationWorkspace;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\E2eTesting\Process\ContaoConsole;
use Contao\E2eTesting\Process\ProcessRunner;
use Contao\E2eTesting\Process\ProcessRunnerInterface;
use Contao\InstallationRecipe\Cache\InMemoryCache;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\File\FileMapping;
use Contao\InstallationRecipe\Fixture\FixtureResult;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class InstallationBuilderTest extends TestCase
{
    private string $directory;

    private ManagedEditionConfig $config;

    /**
     * @var list<string>
     */
    private array $commands = [];

    private bool $failComposer = false;

    private bool $failAutoload = false;

    private bool $failMigration = false;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/installation-builder-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->directory.'/new.txt', 'new recipe content');
        $filesystem->dumpFile($this->directory.'/fixtures.yaml', "tl_page: []\n");

        $recipe = InstallationRecipe::create(ComposerConfig::managedEdition('^5.7'))
            ->withFileMapping(new FileMapping($this->directory.'/new.txt', 'templates/new.txt'))
            ->withFixtureFile($this->directory.'/fixtures.yaml')
        ;
        $this->config = ManagedEditionConfig::create($recipe, $this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testFreshInstallationBuildsDependenciesAndPreparesTheApplication(): void
    {
        $database = $this->database();
        $installation = $this->installation();
        $this->builder()->prepare($this->config, $installation, $database);

        $this->assertSame(['composer', 'autoload', 'setup', 'help', 'migrate'], $this->commands);
        $this->assertSame('installed dependencies', file_get_contents($installation->directory.'/vendor/installed.txt'));
        $this->assertSame('new recipe content', file_get_contents($installation->directory.'/templates/new.txt'));
        $this->assertManifest($installation);
        $this->assertSame([], glob($installation->directory.'.building-*'));
    }

    public function testRecipeBootstrapClassesAreAvailableToPluginsDuringComposerUpdate(): void
    {
        $config = $this->bootstrapConfig();
        $installation = $this->installation();
        $this->builder()->prepare($config, $installation, $this->database());

        $this->assertSame('RecipeFixtures\\Bootstrap', file_get_contents($installation->directory.'/bootstrap-result'));
        $this->assertFileExists($installation->directory.'/src/Bootstrap.php');
    }

    public function testMappedClassmapClassesAreAvailableAfterAWarmApplicationChange(): void
    {
        $config = $this->bootstrapConfig();
        $installation = $this->installation();
        $builder = $this->builder();
        $builder->prepare($config, $installation, $this->database());

        $this->commands = [];
        (new Filesystem())->dumpFile($this->directory.'/Added.php', '<?php namespace RecipeFixtures; final class Added {}');
        $recipe = $config->recipe->withFileMapping(new FileMapping($this->directory.'/Added.php', 'src/Added.php'));
        $changed = ManagedEditionConfig::create($recipe, $this->directory);
        $installation = new InstallationWorkspace($installation->directory, new FingerprintSet('dependencies', 'changed-application', 'fixtures'));
        $builder->prepare($changed, $installation, $this->database());
        $output = (new ProcessRunner())->run(
            [PHP_BINARY, '-r', 'require "vendor/autoload.php"; echo class_exists("RecipeFixtures\\Added") ? "available" : "missing";'],
            $installation->directory,
        );

        $this->assertSame('available', $output);
        $this->assertSame(['autoload', 'setup', 'help', 'migrate'], $this->commands);
    }

    public function testCachedInstallationKeepsItsFilesAndResetsFixtures(): void
    {
        $database = $this->database();
        $installation = $this->installation();
        $this->cache($installation);
        $this->builder()->prepare($this->config, $installation, $database);

        $this->assertSame([], $this->commands);
        $this->assertSame('cached dependencies', file_get_contents($installation->directory.'/vendor/installed.txt'));
        $this->assertSame('cached application', file_get_contents($installation->directory.'/templates/new.txt'));
        $this->assertManifest($installation);
    }

    public function testCachedInstallationWithoutSchemaMigratesWithoutRebuildingFiles(): void
    {
        $database = $this->database(false);
        $installation = $this->installation();
        $this->cache($installation);
        $this->builder()->prepare($this->config, $installation, $database);

        $this->assertSame(['help', 'migrate'], $this->commands);
        $this->assertSame('cached dependencies', file_get_contents($installation->directory.'/vendor/installed.txt'));
        $this->assertSame('cached application', file_get_contents($installation->directory.'/templates/new.txt'));
    }

    public function testMigrationInvalidatesItsCacheBeforeChangingTheSchema(): void
    {
        $database = $this->createMock(InstallationDatabaseInterface::class);
        $database
            ->method('hasSchema')
            ->willReturn(false)
        ;

        $database
            ->expects($this->once())
            ->method('reset')
            ->willReturn(new FixtureResult([]))
        ;
        $shared = new InMemoryCache();
        $shared->set('unrelated', 'kept');

        $cache = $shared->scope($database);
        $cache->set('metadata', 'stale');
        $database
            ->method('applicationUrl')
            ->willReturnCallback(
                function () use ($cache): string {
                    $this->assertFalse($cache->has('metadata'));

                    return 'mysql://localhost/builder-test';
                },
            )
        ;
        $installation = $this->installation();
        $this->cache($installation);
        $this->builder($cache)->prepare($this->config, $installation, $database);

        $this->assertSame(['help', 'migrate'], $this->commands);
        $this->assertSame('kept', $shared->get('unrelated'));
    }

    public function testWarmFixtureResetKeepsItsCache(): void
    {
        $database = $this->database();
        $cache = new InMemoryCache();
        $cache->set('metadata', 'cached');

        $installation = $this->installation();
        $this->cache($installation);
        $this->builder($cache)->prepare($this->config, $installation, $database);

        $this->assertSame('cached', $cache->get('metadata'));
        $this->assertSame([], $this->commands);
    }

    public function testApplicationChangesReplaceRecipeFilesAndKeepDependencies(): void
    {
        $database = $this->database();
        $installation = $this->installation();
        $this->cache($installation, new InstallationManifest('dependencies', 'previous-application', ['templates/old.txt']));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($installation->directory.'/templates/old.txt', 'old recipe');
        $filesystem->dumpFile($installation->directory.'/var/cache/prod/stale.txt', 'old cache');
        $this->builder()->prepare($this->config, $installation, $database);

        $this->assertSame(['autoload', 'setup', 'help', 'migrate'], $this->commands);
        $this->assertSame('cached dependencies', file_get_contents($installation->directory.'/vendor/installed.txt'));
        $this->assertSame('new recipe content', file_get_contents($installation->directory.'/templates/new.txt'));
        $this->assertFileDoesNotExist($installation->directory.'/templates/old.txt');
        $this->assertDirectoryDoesNotExist($installation->directory.'/var/cache');
        $this->assertManifest($installation);
    }

    public function testFailedAutoloadRefreshCanRetryNewMappingsWithoutReinstallingDependencies(): void
    {
        $recipe = $this->config->recipe->withFileMapping(new FileMapping($this->directory.'/new.txt', 'templates/added.txt'));
        $config = ManagedEditionConfig::create($recipe, $this->directory);
        $installation = $this->installation();
        $this->cache($installation, new InstallationManifest('dependencies', 'previous-application', ['templates/new.txt']));
        $this->failAutoload = true;
        $builder = $this->builder();

        try {
            $builder->prepare($config, $installation, $this->createStub(InstallationDatabaseInterface::class));
            $this->fail('The failed autoload refresh must be reported.');
        } catch (ProcessFailedException $exception) {
            $this->assertSame('Autoload failed', $exception->getMessage());
        }

        $manifest = InstallationManifest::read($installation->directory.'/.contao-e2e-manifest.json');
        $this->assertNotNull($manifest);
        $this->assertNull($manifest->application);
        $this->assertSame(['templates/new.txt', 'templates/added.txt'], $manifest->mappedTargets);
        $this->failAutoload = false;
        $builder->prepare($config, $installation, $this->database());

        $this->assertSame(['autoload', 'autoload', 'setup', 'help', 'migrate'], $this->commands);
        $this->assertSame('cached dependencies', file_get_contents($installation->directory.'/vendor/installed.txt'));
        $this->assertSame('new recipe content', file_get_contents($installation->directory.'/templates/added.txt'));
    }

    #[DataProvider('provideFailedMigrationStates')]
    public function testFailedMigrationIsRetriedEvenWhenTheDatabaseHasASchema(bool $applicationChanged, bool $hasSchema): void
    {
        $installation = $this->installation();
        $this->cache($installation, new InstallationManifest('dependencies', $applicationChanged ? 'previous-application' : 'application', ['templates/new.txt']));
        $database = $this->createMock(InstallationDatabaseInterface::class);
        $database
            ->method('applicationUrl')
            ->willReturn('mysql://localhost/builder-test')
        ;

        $database
            ->method('hasSchema')
            ->willReturn($hasSchema)
        ;

        $database
            ->expects($this->never())
            ->method('reset')
        ;
        $this->failMigration = true;
        $builder = $this->builder();

        try {
            $builder->prepare($this->config, $installation, $database);
            $this->fail('The failed migration must be reported.');
        } catch (ProcessFailedException $exception) {
            $this->assertSame('Migration failed', $exception->getMessage());
        }

        $manifest = InstallationManifest::read($installation->directory.'/.contao-e2e-manifest.json');
        $this->assertNotNull($manifest);
        $this->assertNull($manifest->application);
        $this->failMigration = false;
        $builder->prepare($this->config, $installation, $this->database());
        $initialCommands = $applicationChanged ? ['autoload', 'setup', 'help', 'migrate'] : ['help', 'migrate'];

        $this->assertSame([...$initialCommands, 'autoload', 'setup', 'help', 'migrate'], $this->commands);
        $this->assertSame('cached dependencies', file_get_contents($installation->directory.'/vendor/installed.txt'));
        $this->assertManifest($installation);
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function provideFailedMigrationStates(): iterable
    {
        yield 'changed application with an existing schema' => [true, true];
        yield 'cached application with a partially created schema' => [false, false];
    }

    #[DataProvider('invalidDependencyCaches')]
    public function testInvalidDependencyCacheTriggersAFreshBuild(string $dependency, bool $removeVendor): void
    {
        $database = $this->database();
        $installation = $this->installation();
        $this->cache($installation, new InstallationManifest($dependency, 'application'));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($installation->directory.'/stale.txt', 'old installation');
        if ($removeVendor) {
            $filesystem->remove($installation->directory.'/vendor');
        }
        $this->builder()->prepare($this->config, $installation, $database);

        $this->assertSame(['composer', 'autoload', 'setup', 'help', 'migrate'], $this->commands);
        $this->assertSame('installed dependencies', file_get_contents($installation->directory.'/vendor/installed.txt'));
        $this->assertSame('new recipe content', file_get_contents($installation->directory.'/templates/new.txt'));
        $this->assertFileDoesNotExist($installation->directory.'/stale.txt');
        $this->assertManifest($installation);
    }

    public static function invalidDependencyCaches(): iterable
    {
        yield 'changed dependencies' => ['previous-dependencies', false];
        yield 'missing vendor' => ['dependencies', true];
    }

    public function testFailedDependencyBuildKeepsTheCachedInstallationAndRemovesPartialFiles(): void
    {
        $database = $this->createMock(InstallationDatabaseInterface::class);
        $database
            ->expects($this->never())
            ->method('create')
        ;

        $database
            ->expects($this->never())
            ->method('reset')
        ;
        $installation = $this->installation();
        $previous = new InstallationManifest('previous-dependencies', 'previous-application');
        $this->cache($installation, $previous);
        $manifestPath = $installation->directory.'/.contao-e2e-manifest.json';
        $previousContent = file_get_contents($manifestPath);
        $this->failComposer = true;

        try {
            $this->builder()->prepare($this->config, $installation, $database);
            $this->fail('The failed dependency build must be reported.');
        } catch (ProcessFailedException $exception) {
            $this->assertSame('Composer failed', $exception->getMessage());
        }

        $this->assertSame(['composer'], $this->commands);
        $this->assertSame('cached dependencies', file_get_contents($installation->directory.'/vendor/installed.txt'));
        $this->assertSame('cached application', file_get_contents($installation->directory.'/templates/new.txt'));
        $this->assertSame($previousContent, file_get_contents($manifestPath));
        $this->assertSame([], glob($installation->directory.'.building-*'));
    }

    private function database(bool $hasSchema = true): InstallationDatabaseInterface&MockObject
    {
        $database = $this->createMock(InstallationDatabaseInterface::class);
        $database
            ->method('applicationUrl')
            ->willReturn('mysql://localhost/builder-test')
        ;

        $database
            ->method('hasSchema')
            ->willReturn($hasSchema)
        ;

        $database
            ->expects($this->once())
            ->method('create')
        ;

        $database
            ->expects($this->once())
            ->method('reset')
            ->with($this->config->recipe->fixtures)
            ->willReturn(new FixtureResult([]))
        ;

        return $database;
    }

    private function installation(): InstallationWorkspace
    {
        return new InstallationWorkspace(
            $this->directory.'/installation/project',
            new FingerprintSet('dependencies', 'application', 'fixtures'),
        );
    }

    private function cache(InstallationWorkspace $installation, InstallationManifest|null $manifest = null): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($installation->directory.'/composer.json', $this->config->recipe->composer->toJson($installation->directory));
        $filesystem->dumpFile($installation->directory.'/vendor/installed.txt', 'cached dependencies');

        $target = $manifest?->mappedTargets[0] ?? 'templates/new.txt';
        $filesystem->dumpFile($installation->directory.'/'.$target, 'cached application');
        ($manifest ?? new InstallationManifest('dependencies', 'application', ['templates/new.txt']))
            ->write($installation->directory.'/.contao-e2e-manifest.json')
        ;
    }

    private function builder(InMemoryCache $cache = new InMemoryCache()): InstallationBuilder
    {
        $runner = $this->createStub(ProcessRunnerInterface::class);
        $runner
            ->method('run')
            ->willReturnCallback($this->runCommand(...))
        ;

        return new InstallationBuilder(new ComposerInstaller($runner), new ApplicationPreparer(), new ContaoConsole($runner), $cache);
    }

    /**
     * @param list<string> $command
     */
    private function runCommand(array $command, string $directory): string
    {
        if ('composer' === $command[0]) {
            $this->commands[] = 'dump-autoload' === $command[1] ? 'autoload' : 'composer';
            $this->assertFileExists($directory.'/composer.json');
            $composer = json_decode((string) file_get_contents($directory.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
            if (isset($composer['extra']['runtime']['class'])) {
                (new ProcessRunner())->run($command, $directory, ['COMPOSER_HOME' => $this->directory.'/composer-home', 'COMPOSER_ALLOW_SUPERUSER' => '1']);
            }
            if ('dump-autoload' === $command[1]) {
                if ($this->failAutoload) {
                    throw new ProcessFailedException('Autoload failed');
                }

                return '';
            }

            (new Filesystem())->dumpFile($directory.'/vendor/installed.txt', 'installed dependencies');
            if ($this->failComposer) {
                throw new ProcessFailedException('Composer failed');
            }

            return '';
        }

        $action = 'contao-setup' === basename($command[1]) ? 'setup' : $command[2];
        $this->commands[] = 'contao:migrate' === $action ? 'migrate' : $action;
        if ('contao:migrate' === $action && $this->failMigration) {
            throw new ProcessFailedException('Migration failed');
        }

        return 'help' === $action ? '--with-deletes --no-backup' : '';
    }

    private function bootstrapConfig(): ManagedEditionConfig
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->directory.'/Bootstrap.php', <<<'PHP'
            <?php
            namespace RecipeFixtures;
            final class Bootstrap
            {
                public function __construct()
                {
                    file_put_contents('bootstrap-result', self::class);
                }
            }
            PHP);
        $this->createBootstrapPlugin();
        $composer = $this->config->recipe->composer
            ->withOverrides(['require' => []])
            ->withOverrides([
                'require' => ['recipe-fixtures/bootstrap-plugin' => '1.0.0'],
                'repositories' => [
                    'bootstrap' => ['type' => 'path', 'url' => $this->directory.'/plugin', 'options' => ['symlink' => false]],
                    'packagist.org' => false,
                ],
                'config' => ['allow-plugins' => ['recipe-fixtures/bootstrap-plugin' => true]],
                'autoload' => ['classmap' => ['src/']],
                'extra' => ['runtime' => ['class' => 'RecipeFixtures\\Bootstrap']],
            ])
        ;

        $recipe = $this->config->recipe->withComposer($composer)
            ->withFileMapping(new FileMapping($this->directory.'/Bootstrap.php', 'src/Bootstrap.php', false))
        ;

        return ManagedEditionConfig::create($recipe, $this->directory);
    }

    private function createBootstrapPlugin(): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->directory.'/plugin/composer.json', json_encode([
            'name' => 'recipe-fixtures/bootstrap-plugin',
            'version' => '1.0.0',
            'type' => 'composer-plugin',
            'require' => ['composer-plugin-api' => '^2.0'],
            'autoload' => ['psr-4' => ['RecipePlugin\\' => 'src/']],
            'extra' => ['class' => 'RecipePlugin\\BootstrapPlugin'],
        ], JSON_THROW_ON_ERROR));
        $filesystem->dumpFile($this->directory.'/plugin/src/BootstrapPlugin.php', <<<'PHP'
            <?php
            namespace RecipePlugin;
            use Composer\Composer;
            use Composer\EventDispatcher\EventSubscriberInterface;
            use Composer\IO\IOInterface;
            use Composer\Plugin\PluginInterface;
            use Composer\Script\Event;
            use Composer\Script\ScriptEvents;
            final class BootstrapPlugin implements PluginInterface, EventSubscriberInterface
            {
                public function activate(Composer $composer, IOInterface $io): void {}
                public function deactivate(Composer $composer, IOInterface $io): void {}
                public function uninstall(Composer $composer, IOInterface $io): void {}
                public static function getSubscribedEvents(): array
                {
                    return [ScriptEvents::POST_AUTOLOAD_DUMP => 'onAutoload'];
                }
                public function onAutoload(Event $event): void
                {
                    require getcwd().'/vendor/autoload.php';
                    $class = $event->getComposer()->getPackage()->getExtra()['runtime']['class'];
                    new $class();
                }
            }
            PHP);
    }

    private function assertManifest(InstallationWorkspace $installation): void
    {
        $manifest = InstallationManifest::read($installation->directory.'/.contao-e2e-manifest.json');
        $this->assertNotNull($manifest);
        $this->assertSame('dependencies', $manifest->dependency);
        $this->assertSame('application', $manifest->application);
        $this->assertSame(['templates/new.txt'], $manifest->mappedTargets);
    }
}
