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
use Contao\InstallationRecipe\Exception\InvalidRecipeException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class RecipeArchiveTest extends TestCase
{
    public function testLoadsAndCleansUpAPortableRecipe(): void
    {
        $archivePath = $this->archive([
            'recipe.yaml' => <<<'YAML'
                format: 1
                name: acme/example-theme
                composer: composer.json
                config:
                    - config/theme.yaml
                fixtures:
                    - fixtures/pages.yaml
                files:
                    - source: files/theme
                      target: files/theme
                YAML,
            'composer.json' => '{"require":{"acme/theme-bundle":"^1.0"}}',
            'config/theme.yaml' => "contao:\n  localconfig:\n    example: true\n",
            'fixtures/pages.yaml' => "tl_page:\n  root:\n    title: Example\n",
            'files/theme/style.css' => 'body {}',
        ]);

        $archive = RecipeArchive::open($archivePath);

        $this->assertSame('acme/example-theme', $archive->recipe->descriptor->name);
        $this->assertSame(['acme/theme-bundle' => '^1.0'], $archive->recipe->dependencies->requirements);
        $this->assertCount(1, $archive->recipe->content->assets->configFragments);
        $this->assertCount(1, $archive->recipe->content->fixtures->files);
        $this->assertFileExists($archive->recipe->content->assets->fileMappings[0]->source.'/style.css');

        $directory = $archive->directory;
        $archive->close();
        $this->assertDirectoryDoesNotExist($directory);
    }

    public function testRejectsArchivePathTraversal(): void
    {
        $archivePath = $this->archive([
            'recipe.yaml' => "format: 1\nname: acme/example\n",
            '../outside.txt' => 'unsafe',
        ]);

        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('has an unsafe path');

        RecipeArchive::open($archivePath);
    }

    public function testRejectsUnsupportedComposerKeys(): void
    {
        $archivePath = $this->archive([
            'recipe.yaml' => "format: 1\nname: acme/example\ncomposer: composer.json\n",
            'composer.json' => '{"scripts":{"post-update-cmd":"unsafe"}}',
        ]);

        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('may only contain "require" and "require-dev"');

        RecipeArchive::open($archivePath);
    }

    public function testRejectsSymbolicLinks(): void
    {
        $archivePath = $this->symbolicLinkArchive();

        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('is a symbolic link');

        RecipeArchive::open($archivePath);
    }

    public function testRejectsManifestPathsOutsideTheArchive(): void
    {
        $archivePath = $this->archive([
            'recipe.yaml' => "format: 1\nname: acme/example\nconfig: [../outside.yaml]\n",
        ]);

        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('path "../outside.yaml" is invalid');

        RecipeArchive::open($archivePath);
    }

    /**
     * @param array<string, string> $entries
     */
    private function archive(array $entries): string
    {
        $directory = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests';
        (new Filesystem())->mkdir($directory);
        $path = $directory.'/recipe-'.bin2hex(random_bytes(6)).'.zip';
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));

        foreach ($entries as $name => $contents) {
            $this->assertTrue($archive->addFromString($name, $contents));
        }

        $this->assertTrue($archive->close());

        return $path;
    }

    private function symbolicLinkArchive(): string
    {
        $directory = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests';
        $path = $directory.'/recipe-link-'.bin2hex(random_bytes(6)).'.zip';
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $this->assertTrue($archive->addFromString('recipe.yaml', "format: 1\nname: acme/example\n"));
        $this->assertTrue($archive->addFromString('link', 'target'));
        $this->assertTrue($archive->setExternalAttributesName('link', \ZipArchive::OPSYS_UNIX, 0120777 << 16));
        $this->assertTrue($archive->close());

        return $path;
    }
}
