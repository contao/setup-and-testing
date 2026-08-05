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

use Contao\InstallationRecipe\Archive\RecipeArchive;
use Contao\InstallationRecipe\Installation\InstallationRuntimeInterface;
use Contao\InstallationRecipe\Installation\InstallationTarget;
use Contao\InstallationRecipe\Installation\RecipeInstaller;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class RecipeInstallerTest extends TestCase
{
    public function testInstallsAnArchiveIntoAnExistingApplication(): void
    {
        $directory = $this->targetDirectory();
        $archive = RecipeArchive::open($this->archive());
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $runtime = new TestInstallationRuntime($connection);

        $result = (new RecipeInstaller())->install(
            $archive->recipe,
            new InstallationTarget($directory, $connection, $runtime),
        );

        $composer = json_decode((string) file_get_contents($directory.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('^1.0', $composer['require']['acme/theme-bundle']);
        $this->assertSame('^2.0', $composer['require']['existing/package']);
        $this->assertSame(['dependencies', 'migrations'], $runtime->calls);
        $this->assertSame('Existing', Yaml::parseFile($directory.'/config/config.yaml')['framework']['secret']);
        $this->assertFalse(Yaml::parseFile($directory.'/config/config.yaml')['framework']['csrf_protection']);
        $this->assertSame('Example', $connection->fetchOne('SELECT title FROM example'));
        $this->assertSame('1', (string) $result->fixtures->value('page'));
        $this->assertFileExists($directory.'/files/theme/style.css');
        $this->assertFileExists($directory.'/.contao-recipes/acme--example-theme.json');
    }

    private function targetDirectory(): string
    {
        $directory = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests/install-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory.'/config');
        $filesystem->dumpFile($directory.'/composer.json', <<<'JSON'
            {
                "name": "acme/application",
                "require": {
                    "existing/package": "^2.0"
                }
            }
            JSON);
        $filesystem->dumpFile($directory.'/config/config.yaml', "framework:\n  secret: Existing\n");

        return $directory;
    }

    private function archive(): string
    {
        $directory = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests';
        $path = $directory.'/install-recipe-'.bin2hex(random_bytes(6)).'.zip';
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $archive->addFromString('recipe.yaml', $this->manifest());
        $archive->addFromString('composer.json', '{"require":{"acme/theme-bundle":"^1.0"}}');
        $archive->addFromString('config/theme.yaml', "framework:\n  csrf_protection: false\n");
        $archive->addFromString('fixtures/content.yaml', "example:\n  page:\n    title: Example\n");
        $archive->addFromString('files/theme/style.css', 'body {}');
        $this->assertTrue($archive->close());

        return $path;
    }

    private function manifest(): string
    {
        return <<<'YAML'
            format: 1
            name: acme/example-theme
            composer: composer.json
            config:
                - config/theme.yaml
            fixtures:
                - fixtures/content.yaml
            files:
                - source: files/theme
                  target: files/theme
            YAML;
    }
}

final class TestInstallationRuntime implements InstallationRuntimeInterface
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function installDependencies(string $targetDirectory): void
    {
        $this->calls[] = 'dependencies';
    }

    public function migrate(string $targetDirectory): void
    {
        $this->calls[] = 'migrations';
        $this->connection->executeStatement('CREATE TABLE example (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL)');
    }
}
