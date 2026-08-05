<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Tests;

use Contao\E2eTestBundle\Composer\MonorepoProject;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class MonorepoProjectTest extends TestCase
{
    /**
     * @param array<string, mixed> $rootComposer
     */
    #[DataProvider('provideRootComposerFiles')]
    public function testDiscoversTheMonorepoVersion(array $rootComposer, string $version): void
    {
        $directory = $this->createMonorepo($rootComposer);

        $this->assertSame($version, MonorepoProject::discover($directory)->version);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideRootComposerFiles(): iterable
    {
        yield 'explicit version' => [['version' => '2.3.x-dev'], '2.3.x-dev'];
        yield 'branch alias' => [['extra' => ['branch-alias' => ['dev-main' => '6.1-dev']]], '6.1.x-dev'];
        yield 'normalized branch alias' => [['extra' => ['branch-alias' => ['dev-main' => '6.1.x-dev']]], '6.1.x-dev'];
        yield 'no version information' => [[], 'dev-main'];
    }

    public function testConfiguresPathPackagesUsingTheirComposerNames(): void
    {
        $directory = $this->createMonorepo(['version' => '2.3.x-dev']);
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory.'/packages/example');
        $filesystem->dumpFile($directory.'/packages/example/composer.json', json_encode(['name' => 'acme/example'], JSON_THROW_ON_ERROR));

        $monorepo = MonorepoProject::discover($directory);
        $composer = $monorepo->configureComposer(ComposerConfig::managedEdition('^5.7'), 'packages/example');
        $config = $composer->toArray($directory);

        $this->assertSame('2.3.x-dev', $config['require']['acme/example']);
        $this->assertSame('packages/example', $config['repositories'][0]['url']);
    }

    /**
     * @param array<string, mixed> $composer
     */
    private function createMonorepo(array $composer): string
    {
        $directory = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests/monorepo-'.bin2hex(random_bytes(6));
        (new Filesystem())->dumpFile($directory.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));

        return $directory;
    }
}
