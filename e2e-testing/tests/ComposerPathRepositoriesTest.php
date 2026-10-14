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

use Contao\E2eTesting\Composer\ComposerPathRepositories;
use Contao\E2eTesting\Exception\E2eTestException;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class ComposerPathRepositoriesTest extends TestCase
{
    #[DataProvider('providePathPatterns')]
    public function testResolvesRelativeWildcardRepositoriesFromTheComposerFile(string $pattern): void
    {
        if (str_contains($pattern, '{') && !\defined('GLOB_BRACE')) {
            $this->markTestSkipped('Composer brace patterns are not supported on this platform.');
        }

        $directory = Path::canonicalize(sys_get_temp_dir().'/composer-config-'.bin2hex(random_bytes(6)));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($directory.'/packages/first/composer.json', '{"name":"acme/first"}');
        $filesystem->dumpFile($directory.'/packages/second/composer.json', '{"name":"acme/second"}');
        $filesystem->dumpFile($directory.'/composer.json', json_encode([
            'repositories' => ['local' => ['type' => 'path', 'url' => 'packages/'.$pattern], 'packagist.org' => false],
        ], JSON_THROW_ON_ERROR));

        try {
            $config = ComposerConfig::fromFile($directory.'/composer.json')->withOverrides(['extra' => ['example' => false]]);
            $this->assertSame([$directory.'/packages/first', $directory.'/packages/second'], (new ComposerPathRepositories())->metadata($config, $directory)->directories);
            $exported = $config->toArray($directory.'/installation');
            $this->assertSame('../packages/'.$pattern, $exported['repositories']['local']['url']);
            $this->assertFalse($exported['repositories']['packagist.org']);
            $this->assertSame($exported, $config->toArray($directory.'/installation'));

            $filesystem->dumpFile($directory.'/packages/third/composer.json', '{"name":"acme/third"}');
            $this->assertCount(3, (new ComposerPathRepositories())->metadata($config, $directory)->directories);
        } finally {
            $filesystem->remove($directory);
        }
    }

    public function testUsesComposerPackageFiltersWhenTrackingPathRepositories(): void
    {
        $directory = Path::canonicalize(sys_get_temp_dir().'/composer-config-'.bin2hex(random_bytes(6)));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($directory.'/first/composer.json', '{"name":"acme/first"}');
        $filesystem->dumpFile($directory.'/second/composer.json', '{"name":"acme/second"}');

        $config = ComposerConfig::managedEdition('^5.7')->withOverrides([
            'repositories' => [[
                'type' => 'path',
                'url' => $directory.'/*',
                'only' => ['acme/first'],
                'canonical' => false,
            ]],
        ]);

        try {
            $this->assertSame([$directory.'/first'], (new ComposerPathRepositories())->metadata($config, $directory)->directories);
            $this->assertSame(['acme/first'], $config->toArray($directory)['repositories'][0]['only']);
            $this->assertFalse($config->toArray($directory)['repositories'][0]['canonical']);
        } finally {
            $filesystem->remove($directory);
        }
    }

    public function testReportsInvalidPathPackageMetadataThroughTheE2eException(): void
    {
        $directory = sys_get_temp_dir().'/composer-config-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($directory.'/composer.json', '{invalid json}');

        $config = ComposerConfig::managedEdition('^5.7')->withOverrides([
            'repositories' => [['type' => 'path', 'url' => $directory]],
        ]);

        try {
            $this->expectException(E2eTestException::class);
            $this->expectExceptionMessage('Cannot read the Composer path repository');
            (new ComposerPathRepositories())->metadata($config, $directory);
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
