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
use Contao\E2eTesting\Process\ProcessRunnerInterface;
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

        $this->assertSame(['composer', 'setup', 'help', 'migrate'], $this->commands);
        $this->assertSame('installed dependencies', file_get_contents($installation->directory.'/vendor/installed.txt'));
        $this->assertSame('new recipe content', file_get_contents($installation->directory.'/templates/new.txt'));
        $this->assertManifest($installation);
        $this->assertSame([], glob($installation->directory.'.building-*'));
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

    public function testApplicationChangesReplaceRecipeFilesAndKeepDependencies(): void
    {
        $database = $this->database();
        $installation = $this->installation();
        $this->cache($installation, new InstallationManifest('dependencies', 'previous-application', ['templates/old.txt']));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($installation->directory.'/templates/old.txt', 'old recipe');
        $filesystem->dumpFile($installation->directory.'/var/cache/prod/stale.txt', 'old cache');
        $this->builder()->prepare($this->config, $installation, $database);

        $this->assertSame(['setup', 'help', 'migrate'], $this->commands);
        $this->assertSame('cached dependencies', file_get_contents($installation->directory.'/vendor/installed.txt'));
        $this->assertSame('new recipe content', file_get_contents($installation->directory.'/templates/new.txt'));
        $this->assertFileDoesNotExist($installation->directory.'/templates/old.txt');
        $this->assertDirectoryDoesNotExist($installation->directory.'/var/cache');
        $this->assertManifest($installation);
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

        $this->assertSame(['composer', 'setup', 'help', 'migrate'], $this->commands);
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
        $filesystem->dumpFile($installation->directory.'/vendor/installed.txt', 'cached dependencies');

        $target = $manifest?->mappedTargets[0] ?? 'templates/new.txt';
        $filesystem->dumpFile($installation->directory.'/'.$target, 'cached application');
        ($manifest ?? new InstallationManifest('dependencies', 'application', ['templates/new.txt']))
            ->write($installation->directory.'/.contao-e2e-manifest.json')
        ;
    }

    private function builder(): InstallationBuilder
    {
        $runner = $this->createStub(ProcessRunnerInterface::class);
        $runner
            ->method('run')
            ->willReturnCallback($this->runCommand(...))
        ;

        return new InstallationBuilder(new ComposerInstaller($runner), new ApplicationPreparer(), new ContaoConsole($runner));
    }

    /**
     * @param list<string> $command
     */
    private function runCommand(array $command, string $directory): string
    {
        if ('composer' === $command[0]) {
            $this->commands[] = 'composer';
            $this->assertFileExists($directory.'/composer.json');
            (new Filesystem())->dumpFile($directory.'/vendor/installed.txt', 'installed dependencies');
            if ($this->failComposer) {
                throw new ProcessFailedException('Composer failed');
            }

            return '';
        }

        $action = 'contao-setup' === basename($command[1]) ? 'setup' : $command[2];
        $this->commands[] = 'contao:migrate' === $action ? 'migrate' : $action;

        return 'help' === $action ? '--with-deletes --no-backup' : '';
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
