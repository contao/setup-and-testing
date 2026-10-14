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

use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Exception\InvalidRecipeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class ComposerConfigTest extends TestCase
{
    public function testBuildsAnImmutableManagedEditionConfiguration(): void
    {
        $base = ComposerConfig::managedEdition('5.7.*');
        $configured = $base
            ->withOverrides([
                'require' => ['contao/news-bundle' => '^5.7', 'acme/example' => '1.0.x-dev'],
                'repositories' => [[
                    'type' => 'path',
                    'url' => __DIR__,
                    'options' => ['symlink' => true, 'versions' => ['acme/example' => '1.0.x-dev']],
                ]],
            ])
        ;

        $this->assertSame(['contao/manager-bundle' => '5.7.*'], $base->toArray(__DIR__)['require']);
        $this->assertSame('assets', $base->toArray(__DIR__)['extra']['contao-component-dir']);
        $this->assertSame('1.0.x-dev', $configured->toArray(__DIR__)['require']['acme/example']);
        $this->assertSame('.', $configured->toArray(__DIR__)['repositories'][0]['url']);
        $this->assertTrue($configured->toArray(__DIR__)['repositories'][0]['options']['symlink']);
    }

    public function testMergesProjectConfigurationWithoutMutatingTheOriginal(): void
    {
        $base = ComposerConfig::managedEdition('^5.7')->withOverrides([
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
            'extra' => ['runtime' => ['class' => 'App\\Runtime', 'options' => ['debug' => true]]],
        ]);
        $configured = $base->withOverrides([
            'require' => ['symfony/runtime' => '^7.0'],
            'config' => ['allow-plugins' => ['vendor/plugin' => true, 'contao/manager-plugin' => false]],
            'extra' => ['runtime' => ['options' => ['debug' => false, 'environment' => 'test']]],
            'autoload' => ['psr-4' => ['Fixtures\\' => 'fixtures/']],
            'scripts' => ['post-update-cmd' => ['@php custom-setup.php']],
            'prefer-stable' => false,
        ])->toArray(__DIR__);

        $this->assertSame(['contao/manager-bundle' => '^5.7', 'symfony/runtime' => '^7.0'], $configured['require']);
        $this->assertTrue($configured['config']['allow-plugins']['vendor/plugin']);
        $this->assertTrue($configured['config']['allow-plugins']['contao-components/installer']);
        $this->assertFalse($configured['config']['allow-plugins']['contao/manager-plugin']);
        $this->assertFalse($configured['config']['allow-plugins']['php-http/discovery']);
        $this->assertSame(['class' => 'App\\Runtime', 'options' => ['debug' => false, 'environment' => 'test']], $configured['extra']['runtime']);
        $this->assertSame(['App\\' => 'src/', 'Fixtures\\' => 'fixtures/'], $configured['autoload']['psr-4']);
        $this->assertSame(['@php custom-setup.php'], $configured['scripts']['post-update-cmd']);
        $this->assertSame(['@php vendor/bin/contao-setup'], $configured['scripts']['post-install-cmd']);
        $this->assertFalse($configured['prefer-stable']);
        $this->assertTrue($base->toArray(__DIR__)['extra']['runtime']['options']['debug']);
        $this->assertTrue($base->toArray(__DIR__)['config']['allow-plugins']['contao/manager-plugin']);
    }

    public function testReplacesListsIncludingEmptyLists(): void
    {
        $base = ComposerConfig::managedEdition('^5.7')->withOverrides([
            'autoload' => ['classmap' => ['src/', 'legacy/']],
        ]);
        $configured = $base->withOverrides([
            'autoload' => ['classmap' => ['fixtures/']],
            'scripts' => ['post-update-cmd' => []],
        ]);

        $this->assertSame(['fixtures/'], $configured->toArray(__DIR__)['autoload']['classmap']);
        $this->assertSame([], $configured->toArray(__DIR__)['scripts']['post-update-cmd']);
        $this->assertSame(['src/', 'legacy/'], $base->toArray(__DIR__)['autoload']['classmap']);
    }

    public function testRetainsPathRepositoriesInTheMergedConfiguration(): void
    {
        $packageDirectory = Path::canonicalize(\dirname(__DIR__));
        $base = ComposerConfig::managedEdition('^5.7')->withOverrides([
            'require' => ['acme/example' => '1.0.x-dev'],
            'repositories' => [[
                'type' => 'path',
                'url' => $packageDirectory,
                'options' => ['symlink' => false, 'versions' => ['acme/example' => '1.0.x-dev']],
            ]],
        ]);
        $configured = $base->withOverrides(['require' => ['acme/other' => '^1.0']]);
        $config = $configured->toArray(__DIR__);

        $this->assertSame('1.0.x-dev', $config['require']['acme/example']);
        $this->assertSame('^5.7', $config['require']['contao/manager-bundle']);
        $this->assertSame('..', $config['repositories'][0]['url']);
        $this->assertFalse($config['repositories'][0]['options']['symlink']);
        $this->assertSame(['acme/example' => '1.0.x-dev'], $config['repositories'][0]['options']['versions']);

        $replaced = $configured->withOverrides(['repositories' => [['type' => 'composer', 'url' => 'https://example.org']]]);
        $this->assertCount(1, $replaced->toArray(__DIR__)['repositories']);
        $this->assertSame([], $configured->withOverrides(['repositories' => []])->toArray(__DIR__)['repositories']);
        $this->assertSame('..', $base->toArray(__DIR__)['repositories'][0]['url']);
    }

    public function testExportsPathRepositoriesWithoutInspectingPackages(): void
    {
        $directory = Path::canonicalize(sys_get_temp_dir().'/composer-config-'.bin2hex(random_bytes(6)));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($directory.'/composer.json', '{invalid package metadata}');

        try {
            $config = ComposerConfig::managedEdition('^5.7')->withOverrides([
                'repositories' => [[
                    'type' => 'path',
                    'url' => $directory,
                    'options' => ['versions' => ['acme/example' => '1.0.x-dev']],
                ]],
            ]);

            $this->assertSame('.', $config->toArray($directory)['repositories'][0]['url']);
            $this->assertSame(['acme/example' => '1.0.x-dev'], $config->toArray($directory)['repositories'][0]['options']['versions']);
        } finally {
            $filesystem->remove($directory);
        }
    }

    public function testPreservesLiteralEnvironmentVariableNamesInsideRepositoryPaths(): void
    {
        $config = ComposerConfig::managedEdition('^5.7')->withOverrides([
            'repositories' => [['type' => 'path', 'url' => __DIR__.'/literal/$HOME']],
        ]);
        $config = $config->withOverrides(['extra' => ['example' => false]]);

        $this->assertSame('literal/$HOME', $config->toArray(__DIR__)['repositories'][0]['url']);
    }

    #[DataProvider('provideExpandedRepositoryPaths')]
    #[RunInSeparateProcess]
    public function testExpandsLeadingEnvironmentVariablesAndHomeDirectories(string $url): void
    {
        $_SERVER['CONTAO_RECIPE_PACKAGE_DIRECTORY'] = __DIR__;
        $_SERVER['HOME'] = __DIR__;
        $config = ComposerConfig::managedEdition('^5.7')->withOverrides([
            'repositories' => [['type' => 'path', 'url' => $url]],
        ]);

        $this->assertSame('fixtures', $config->toArray(__DIR__)['repositories'][0]['url']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideExpandedRepositoryPaths(): iterable
    {
        yield 'dollar variable' => ['$CONTAO_RECIPE_PACKAGE_DIRECTORY/fixtures'];
        yield 'percent variable' => ['%CONTAO_RECIPE_PACKAGE_DIRECTORY%/fixtures'];
        yield 'home directory' => ['~/fixtures'];
        if ('\\' === \DIRECTORY_SEPARATOR) {
            yield 'home directory with backslash' => ['~\\fixtures'];
        }
    }

    #[DataProvider('providePathPatterns')]
    public function testResolvesRelativeWildcardRepositoriesFromTheComposerFile(string $pattern): void
    {
        $directory = Path::canonicalize(sys_get_temp_dir().'/composer-config-'.bin2hex(random_bytes(6)));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($directory.'/composer.json', json_encode([
            'repositories' => ['local' => ['type' => 'path', 'url' => 'packages/'.$pattern], 'packagist.org' => false],
        ], JSON_THROW_ON_ERROR));

        try {
            $config = ComposerConfig::fromFile($directory.'/composer.json')->withOverrides(['extra' => ['example' => false]]);
            $exported = $config->toArray($directory.'/installation');
            $this->assertSame('../packages/'.$pattern, $exported['repositories']['local']['url']);
            $this->assertFalse($exported['repositories']['packagist.org']);
            $this->assertSame($exported, $config->toArray($directory.'/installation'));
        } finally {
            $filesystem->remove($directory);
        }
    }

    public function testReportsInvalidComposerFilesThroughTheRecipeException(): void
    {
        $directory = sys_get_temp_dir().'/composer-config-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($directory.'/composer.json', '{invalid json}');

        try {
            $this->expectException(InvalidRecipeException::class);
            $this->expectExceptionMessage('The Composer file "'.$directory.'/composer.json" is invalid');
            ComposerConfig::fromFile($directory.'/composer.json');
        } finally {
            $filesystem->remove($directory);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePathPatterns(): iterable
    {
        yield 'wildcard' => ['*'];
        yield 'brace pattern' => ['{first,second,third}'];
    }
}
