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

use Contao\InstallationRecipe\Composer\ComposerDependencies;
use Contao\InstallationRecipe\Configuration\ConfigFragment;
use Contao\InstallationRecipe\Exception\InvalidRecipeException;
use Contao\InstallationRecipe\File\FileMapping;
use Contao\InstallationRecipe\File\PortableFilePolicy;
use Contao\InstallationRecipe\Fixture\FixtureSet;
use Contao\InstallationRecipe\Installation\InstallationRuntimeInterface;
use Contao\InstallationRecipe\Installation\InstallationTarget;
use Contao\InstallationRecipe\Installation\RecipeInstallerFactory;
use Contao\InstallationRecipe\Recipe\PortableInstallationRecipe;
use Contao\InstallationRecipe\Recipe\RecipeAssets;
use Contao\InstallationRecipe\Recipe\RecipeContent;
use Contao\InstallationRecipe\Recipe\RecipeDescriptor;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class RecipeInstallationPlanTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/recipe-plan-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir([$this->directory.'/project', $this->directory.'/recipe']);
        $filesystem->dumpFile($this->directory.'/project/composer.json', '{}');
        $filesystem->dumpFile($this->directory.'/recipe/style.css', 'body {}');
        $filesystem->dumpFile($this->directory.'/recipe/config.yaml', "framework:\n  default_locale: en\n");
        $filesystem->dumpFile($this->directory.'/recipe/fixtures.yaml', "example:\n  page:\n    title: Example\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    #[DataProvider('changedFiles')]
    public function testRejectsChangesAfterPlanning(string $path, string $contents): void
    {
        $installer = (new RecipeInstallerFactory())->create();
        $target = $this->target();
        $plan = $installer->plan($this->recipe(), $target);
        (new Filesystem())->dumpFile($this->directory.'/'.$path, $contents);

        try {
            $installer->install($plan, $target);
            $this->fail('A stale installation plan must be rejected.');
        } catch (InvalidRecipeException $exception) {
            $this->assertStringContainsString('changed after review', $exception->getMessage());
            $this->assertFileDoesNotExist($this->directory.'/project/files/theme/style.css');
            $this->assertFileDoesNotExist($this->directory.'/project/config/config.yaml');
        }
    }

    public static function changedFiles(): iterable
    {
        yield 'Composer target' => ['project/composer.json', '{"name":"acme/changed"}'];
        yield 'asset content' => ['recipe/style.css', 'changed'];
        yield 'configuration' => ['recipe/config.yaml', 'framework: {default_locale: de}'];
        yield 'fixtures' => ['recipe/fixtures.yaml', 'example: {page: {title: Changed}}'];
    }

    public function testPlanExposesChangesWithoutWriting(): void
    {
        $plan = (new RecipeInstallerFactory())->create()->plan($this->recipe(), $this->target());
        $this->assertSame('^1.0', $plan->changes['composer']['require']['acme/theme']);
        $this->assertStringContainsString('default_locale: en', $plan->changes['configuration']['fragments'][0]);
        $this->assertStringContainsString('title: Example', $plan->changes['fixtures'][0]);
        $this->assertSame('files/theme/style.css', $plan->changes['files'][0]['target']);
        $this->assertNull($plan->changes['files'][0]['before']);
        $this->assertSame('{}', file_get_contents($this->directory.'/project/composer.json'));
        $this->assertFileDoesNotExist($this->directory.'/project/config/config.yaml');
    }

    public function testRejectsAnotherTarget(): void
    {
        $installer = (new RecipeInstallerFactory())->create();
        $plan = $installer->plan($this->recipe(), $this->target());
        (new Filesystem())->mkdir($this->directory.'/another');
        (new Filesystem())->dumpFile($this->directory.'/another/composer.json', '{}');
        $target = $this->target();
        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('changed after review');
        $installer->install($plan, new InstallationTarget($this->directory.'/another', $target->connection, $target->runtime));
    }

    #[DataProvider('documentLinks')]
    public function testRejectsDocumentLinksOutsideTheInstallationBeforeWriting(string $path): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->directory.'/outside');
        $filesystem->remove($this->directory.'/project/'.$path);

        if (!@symlink($this->directory.'/outside', $this->directory.'/project/'.$path)) {
            $this->markTestSkipped('Symbolic links are unavailable on this platform.');
        }

        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('outside');
        (new RecipeInstallerFactory())->create()->plan($this->recipe(), $this->target());
    }

    public static function documentLinks(): iterable
    {
        yield ['composer.json'];
        yield ['config'];
        yield ['.contao-recipes'];
    }

    public function testRejectsAnAssetChangedSinceOverwriteWasReviewed(): void
    {
        $path = $this->directory.'/project/files/theme/style.css';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($path, 'original');

        $recipe = $this->recipe();
        $recipe = new PortableInstallationRecipe(
            $recipe->descriptor,
            $recipe->dependencies,
            new RecipeContent($recipe->content->fixtures, new RecipeAssets(
                $recipe->content->assets->configFragments,
                [new FileMapping($this->directory.'/recipe/style.css', 'files/theme/style.css', true)],
            )),
        );
        $installer = (new RecipeInstallerFactory())->create((new PortableFilePolicy())->withOverwrite(true));
        $target = $this->target();
        $plan = $installer->plan($recipe, $target);
        $this->assertTrue($plan->changes['files'][0]['overwrite']);
        $filesystem->dumpFile($path, 'changed after review');

        try {
            $installer->install($plan, $target);
            $this->fail('Changed overwrite destinations must be rejected.');
        } catch (InvalidRecipeException $exception) {
            $this->assertStringContainsString('changed after review', $exception->getMessage());
            $this->assertSame('changed after review', file_get_contents($path));
            $this->assertSame('{}', file_get_contents($this->directory.'/project/composer.json'));
        }
    }

    public function testRejectsMissingConfigurationBeforeChangingComposer(): void
    {
        $recipe = $this->recipe();
        unlink($this->directory.'/recipe/config.yaml');

        try {
            (new RecipeInstallerFactory())->create()->plan($recipe, $this->target());
            $this->fail('Missing configuration fragments must be rejected.');
        } catch (InvalidRecipeException $exception) {
            $this->assertStringContainsString('fragment must exist', $exception->getMessage());
            $this->assertSame('{}', file_get_contents($this->directory.'/project/composer.json'));
        }
    }

    public function testShowsPhpContentsWithoutExecutingThem(): void
    {
        $contents = '<?php throw new \\LogicException("Do not execute during review");';
        (new Filesystem())->dumpFile($this->directory.'/recipe/dca.php', $contents);
        $recipe = new PortableInstallationRecipe(
            new RecipeDescriptor('acme/dca', 1),
            new ComposerDependencies(),
            new RecipeContent(FixtureSet::empty(), new RecipeAssets(fileMappings: [
                new FileMapping($this->directory.'/recipe/dca.php', 'contao/dca/tl_content.php'),
            ])),
        );
        $plan = (new RecipeInstallerFactory())->create()->plan($recipe, $this->target());
        $this->assertSame('contao/dca/tl_content.php', $plan->changes['files'][0]['target']);
        $this->assertSame(['encoding' => 'utf-8', 'value' => $contents], $plan->changes['files'][0]['contents']);
        $this->assertFileDoesNotExist($this->directory.'/project/contao/dca/tl_content.php');
    }

    public function testRejectsRetargetedLinksWithIdenticalFileContents(): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir([$this->directory.'/project/first', $this->directory.'/project/second']);

        $link = $this->directory.'/project/files';

        if (!@symlink('first', $link)) {
            $this->markTestSkipped('Symbolic links are unavailable on this platform.');
        }

        $installer = (new RecipeInstallerFactory())->create();
        $target = $this->target();
        $plan = $installer->plan($this->recipe(), $target);
        $this->assertSame('first/theme/style.css', $plan->changes['files'][0]['resolved-target']);
        unlink($link);
        $this->assertTrue(symlink('second', $link));
        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('changed after review');
        $installer->install($plan, $target);
    }

    public function testAllowsDocumentLinksInsideTheInstallation(): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->directory.'/project/metadata');
        $filesystem->rename($this->directory.'/project/composer.json', $this->directory.'/project/metadata/composer.json');

        if (!@symlink('metadata/composer.json', $this->directory.'/project/composer.json')) {
            $this->markTestSkipped('Symbolic links are unavailable on this platform.');
        }

        $plan = (new RecipeInstallerFactory())->create()->plan($this->recipe(), $this->target());
        $this->assertSame(realpath($this->directory.'/project'), $plan->targetDirectory);
        $this->assertSame(realpath($this->directory.'/project/metadata/composer.json'), $plan->changes['composer']['destination']);
    }

    #[DataProvider('documentLinks')]
    public function testDocumentLinksCannotBypassProtectedTargets(string $path): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->directory.'/project/vendor');
        $filesystem->remove($this->directory.'/project/'.$path);

        if (!@symlink('vendor', $this->directory.'/project/'.$path)) {
            $this->markTestSkipped('Symbolic links are unavailable on this platform.');
        }

        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('protected');
        (new RecipeInstallerFactory())->create()->plan($this->recipe(), $this->target());
    }

    public function testDocumentLinksCannotBypassResolvedProtectedTargets(): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->directory.'/project/dependencies');
        $filesystem->rename($this->directory.'/project/composer.json', $this->directory.'/project/dependencies/composer.json');

        if (!@symlink('dependencies', $this->directory.'/project/vendor') || !@symlink('dependencies/composer.json', $this->directory.'/project/composer.json')) {
            $this->markTestSkipped('Symbolic links are unavailable on this platform.');
        }

        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('protected');
        (new RecipeInstallerFactory())->create()->plan($this->recipe(), $this->target());
    }

    public function testSnapshotsTaggedConfigurationWithoutParsingWhenNoMergeIsRequested(): void
    {
        $configuration = "services:\n  app.handler:\n    arguments: [!tagged_iterator app.handler]\n";
        (new Filesystem())->dumpFile($this->directory.'/project/config/config.yaml', $configuration);
        $recipe = new PortableInstallationRecipe(
            new RecipeDescriptor('acme/assets', 1),
            new ComposerDependencies(),
            new RecipeContent(FixtureSet::empty(), new RecipeAssets(fileMappings: [
                new FileMapping($this->directory.'/recipe/style.css', 'files/style.css'),
            ])),
        );
        $installer = (new RecipeInstallerFactory())->create();
        $target = $this->target();
        $plan = $installer->plan($recipe, $target);
        $this->assertSame($configuration, $plan->changes['configuration']['before']);
        $this->assertSame([], $plan->changes['configuration']['fragments']);
        (new Filesystem())->dumpFile($this->directory.'/project/config/config.yaml', $configuration."\n");
        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('changed after review');
        $installer->install($plan, $target);
    }

    public function testRejectsMappingsOverwritingTheResolvedComposerDocument(): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->directory.'/project/metadata');
        $filesystem->rename($this->directory.'/project/composer.json', $this->directory.'/project/metadata/composer.json');

        if (!@symlink('metadata/composer.json', $this->directory.'/project/composer.json')) {
            $this->markTestSkipped('Symbolic links are unavailable on this platform.');
        }

        $recipe = new PortableInstallationRecipe(
            new RecipeDescriptor('acme/assets', 1),
            new ComposerDependencies(),
            new RecipeContent(FixtureSet::empty(), new RecipeAssets(fileMappings: [
                new FileMapping($this->directory.'/recipe/style.css', 'metadata/composer.json', true),
            ])),
        );
        $installer = (new RecipeInstallerFactory())->create((new PortableFilePolicy())->withOverwrite(true));
        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('protected');
        $installer->plan($recipe, $this->target());
    }

    private function recipe(): PortableInstallationRecipe
    {
        return new PortableInstallationRecipe(
            new RecipeDescriptor('acme/theme', 1),
            new ComposerDependencies(['acme/theme' => '^1.0']),
            new RecipeContent(
                new FixtureSet([$this->directory.'/recipe/fixtures.yaml']),
                new RecipeAssets(
                    [new ConfigFragment($this->directory.'/recipe/config.yaml')],
                    [new FileMapping($this->directory.'/recipe/style.css', 'files/theme/style.css')],
                ),
            ),
        );
    }

    private function target(): InstallationTarget
    {
        $runtime = new class() implements InstallationRuntimeInterface {
            public function installDependencies(string $targetDirectory): void
            {
                throw new \LogicException('Planning must not install dependencies.');
            }

            public function migrate(string $targetDirectory): void
            {
                throw new \LogicException('Planning must not migrate the database.');
            }
        };

        return new InstallationTarget(
            $this->directory.'/project',
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            $runtime,
        );
    }
}
