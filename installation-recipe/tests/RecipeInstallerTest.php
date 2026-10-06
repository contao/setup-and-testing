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
use Contao\InstallationRecipe\Composer\ComposerDependencies;
use Contao\InstallationRecipe\Fixture\FixtureSet;
use Contao\InstallationRecipe\Installation\InstallationRuntimeInterface;
use Contao\InstallationRecipe\Installation\InstallationTarget;
use Contao\InstallationRecipe\Installation\RecipeInstallerFactory;
use Contao\InstallationRecipe\Recipe\PortableInstallationRecipe;
use Contao\InstallationRecipe\Recipe\RecipeAssets;
use Contao\InstallationRecipe\Recipe\RecipeContent;
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
        $runtime = $this->runtime($connection);

        $installer = (new RecipeInstallerFactory())->create();
        $target = new InstallationTarget($directory, $connection, $runtime);
        $plan = $installer->plan($archive->recipe, $target);
        $this->assertSame('^1.0', $plan->changes['composer']['require']['acme/theme-bundle']);
        $this->assertSame([], $runtime->calls);
        $this->assertFileDoesNotExist($directory.'/files/theme/style.css');
        $result = $installer->install($plan, $target);

        $composer = json_decode((string) file_get_contents($directory.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('^1.0', $composer['require']['acme/theme-bundle']);
        $this->assertSame('^2.0', $composer['require']['existing/package']);
        $this->assertSame(['dependencies', 'migrations'], $runtime->calls);
        $this->assertSame('Existing', Yaml::parseFile($directory.'/config/config.yaml')['framework']['secret']);
        $this->assertFalse(Yaml::parseFile($directory.'/config/config.yaml')['framework']['csrf_protection']);
        $this->assertSame('Example', $connection->fetchOne('SELECT title FROM example'));
        $this->assertSame('1', (string) $result->fixtures->value('page'));
        $this->assertFileExists($directory.'/files/theme/style.css');
        $this->assertSame('<?php throw new \\LogicException("Do not execute during import");', file_get_contents($directory.'/contao/dca/tl_content.php'));
        $this->assertSame('<?php return [];', file_get_contents($directory.'/config/services.php'));
        $this->assertFileExists($directory.'/.contao-recipes/acme--example-theme.json');
    }

    public function testInstallsThroughInternalDocumentLinks(): void
    {
        $directory = $this->targetDirectory();
        $filesystem = new Filesystem();
        $filesystem->mkdir([$directory.'/metadata', $directory.'/journals']);
        $filesystem->rename($directory.'/composer.json', $directory.'/metadata/composer.json');
        $filesystem->rename($directory.'/config', $directory.'/application-config');

        foreach (['composer.json' => 'metadata/composer.json', 'config' => 'application-config', '.contao-recipes' => 'journals'] as $link => $destination) {
            if (!@symlink($destination, $directory.'/'.$link)) {
                $this->markTestSkipped('Symbolic links are unavailable on this platform.');
            }
        }

        $archive = RecipeArchive::open($this->archive());
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $target = new InstallationTarget($directory, $connection, $this->runtime($connection));
        $installer = (new RecipeInstallerFactory())->create();
        $installer->install($installer->plan($archive->recipe, $target), $target);
        $this->assertTrue(is_link($directory.'/composer.json'));
        $this->assertTrue(is_link($directory.'/config'));
        $this->assertTrue(is_link($directory.'/.contao-recipes'));
        $composer = json_decode((string) file_get_contents($directory.'/metadata/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('^1.0', $composer['require']['acme/theme-bundle']);
        $this->assertFalse(Yaml::parseFile($directory.'/application-config/config.yaml')['framework']['csrf_protection']);
        $this->assertFileExists($directory.'/application-config/services.php');
        $this->assertFileExists($directory.'/journals/acme--example-theme.json');
    }

    public function testInstallsFilesWithoutChangingTaggedSymfonyConfiguration(): void
    {
        $directory = $this->targetDirectory();
        $configuration = "services:\n  app.handler:\n    arguments: [!tagged_iterator app.handler]\n";
        (new Filesystem())->dumpFile($directory.'/config/config.yaml', $configuration);
        $archive = RecipeArchive::open($this->archive());

        try {
            $recipe = new PortableInstallationRecipe(
                $archive->recipe->descriptor,
                new ComposerDependencies(),
                new RecipeContent(FixtureSet::empty(), new RecipeAssets(fileMappings: $archive->recipe->content->assets->fileMappings)),
            );
            $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
            $runtime = $this->runtime($connection);
            $target = new InstallationTarget($directory, $connection, $runtime);
            $installer = (new RecipeInstallerFactory())->create();
            $result = $installer->install($installer->plan($recipe, $target), $target);
            $this->assertFalse($result->configurationChanged);
            $this->assertSame($configuration, file_get_contents($directory.'/config/config.yaml'));
            $this->assertSame(['migrations'], $runtime->calls);
            $this->assertSame('body {}', file_get_contents($directory.'/files/theme/style.css'));
        } finally {
            $archive->close();
        }
    }

    /**
     * @return InstallationRuntimeInterface&object{calls: list<string>}
     */
    private function runtime(Connection $connection): InstallationRuntimeInterface
    {
        return new class($connection) implements InstallationRuntimeInterface {
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
        };
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
        $archive->addFromString('files/dca.php', '<?php throw new \\LogicException("Do not execute during import");');
        $archive->addFromString('files/services.php', '<?php return [];');
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
                - source: files/dca.php
                  target: contao/dca/tl_content.php
                - source: files/services.php
                  target: config/services.php
            YAML;
    }
}
