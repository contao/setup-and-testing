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

use Contao\E2eTesting\Cache\FingerprintCalculator;
use Contao\E2eTesting\Cache\SourceFingerprint;
use Contao\E2eTesting\Cache\WorkspaceInitializer;
use Contao\E2eTesting\Installation\InstallationPool;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\E2eTesting\Process\ProcessRunner;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class FingerprintCalculatorTest extends TestCase
{
    public function testSeparatesDependencyApplicationAndDataChanges(): void
    {
        $directory = $this->createInputDirectory();
        $calculator = new FingerprintCalculator(new SourceFingerprint());
        $initial = $calculator->calculate($this->config($directory));
        $configuration = $calculator->configurationFingerprint($this->config($directory));

        (new Filesystem())->dumpFile($directory.'/fixture.yaml', "example:\n  - id: 2\n");
        $fixtureChanged = $calculator->calculate($this->config($directory));
        $this->assertSame($initial->dependency, $fixtureChanged->dependency);
        $this->assertSame($initial->application, $fixtureChanged->application);
        $this->assertNotSame($initial->data, $fixtureChanged->data);
        $this->assertSame($configuration, $calculator->configurationFingerprint($this->config($directory)));

        (new Filesystem())->dumpFile($directory.'/config.yaml', "contao:\n  csrf_cookie_prefix: changed\n");
        $configChanged = $calculator->calculate($this->config($directory));
        $this->assertSame($initial->dependency, $configChanged->dependency);
        $this->assertNotSame($initial->application, $configChanged->application);
        $this->assertSame($configuration, $calculator->configurationFingerprint($this->config($directory)));

        (new Filesystem())->dumpFile($directory.'/tl_content.php', '<?php $GLOBALS["TL_DCA"]["tl_content"]["fields"]["example"]["eval"]["mandatory"] = true;');
        $dcaChanged = $calculator->calculate($this->config($directory));
        $this->assertSame($configChanged->dependency, $dcaChanged->dependency);
        $this->assertNotSame($configChanged->application, $dcaChanged->application);
        $this->assertSame($configuration, $calculator->configurationFingerprint($this->config($directory)));

        (new Filesystem())->dumpFile($directory.'/source/Example.php', '<?php return 2;');
        $sourceChanged = $calculator->calculate($this->config($directory));
        $this->assertSame($dcaChanged->dependency, $sourceChanged->dependency);
        $this->assertNotSame($dcaChanged->application, $sourceChanged->application);
        $this->assertSame($configuration, $calculator->configurationFingerprint($this->config($directory)));
    }

    public function testConfigurationFingerprintSeparatesApplicationVariantsButNotFixtureSets(): void
    {
        $directory = $this->createInputDirectory();
        $calculator = new FingerprintCalculator(new SourceFingerprint());
        $config = $this->config($directory);
        $configuration = $calculator->configurationFingerprint($config);

        try {
            $this->assertNotSame($configuration, $calculator->configurationFingerprint($config->withAppEnvironment('dev')));
            $this->assertNotSame($configuration, $calculator->configurationFingerprint($config->withEnvironmentVariables(['FEATURE' => 'enabled'])));
            $this->assertNotSame($configuration, $calculator->configurationFingerprint($config->withSimulatedOrigins()));
            (new Filesystem())->dumpFile($directory.'/another-config.yaml', "contao: {}\n");
            $recipe = $config->recipe->withConfigFile($directory.'/another-config.yaml');
            $this->assertNotSame($configuration, $calculator->configurationFingerprint(ManagedEditionConfig::create($recipe, $directory)));
            $recipe = InstallationRecipe::create($config->recipe->composer)->withFixtureFile($directory.'/fixture.yaml');
            $this->assertNotSame($configuration, $calculator->configurationFingerprint(ManagedEditionConfig::create($recipe, $directory)));
            $recipe = $config->recipe->withFixtureFile($directory.'/fixture.yaml');
            $this->assertSame($configuration, $calculator->configurationFingerprint(ManagedEditionConfig::create($recipe, $directory)));
        } finally {
            (new Filesystem())->remove($directory);
        }
    }

    #[DataProvider('provideRepositoriesThatMayBeCopied')]
    public function testPathPackagesThatMayBeCopiedRebuildDependenciesWhenSourcesChange(bool|null $symlink, bool $withSymlinkRepository): void
    {
        $directory = $this->createInputDirectory();
        $calculator = new FingerprintCalculator(new SourceFingerprint());
        $config = $this->config($directory);
        $composer = $config->recipe->composer;
        $repositories = $composer->toArray($directory)['repositories'];
        $repositories[0]['url'] = $directory.'/source';
        if (null === $symlink) {
            unset($repositories[0]['options']['symlink']);
        } else {
            $repositories[0]['options']['symlink'] = $symlink;
        }
        if ($withSymlinkRepository) {
            $linkedRepository = $repositories[0];
            $linkedRepository['options']['symlink'] = true;
            $repositories[] = $linkedRepository;
        }

        $recipe = $config->recipe->withComposer($composer->withOverrides(['repositories' => $repositories]));
        $config = ManagedEditionConfig::create($recipe, $directory);

        try {
            $initial = $calculator->calculate($config);
            (new Filesystem())->dumpFile($directory.'/source/Example.php', '<?php return 2;');
            $changed = $calculator->calculate($config);

            $this->assertNotSame($initial->dependency, $changed->dependency);
            $this->assertNotSame($initial->application, $changed->application);
        } finally {
            (new Filesystem())->remove($directory);
        }
    }

    /**
     * @return iterable<string, array{bool|null, bool}>
     */
    public static function provideRepositoriesThatMayBeCopied(): iterable
    {
        yield 'forced mirroring' => [false, false];
        yield 'automatic fallback' => [null, false];
        yield 'same path also has a symlink repository' => [false, true];
    }

    public function testAppEnvironmentInvalidatesThePreparedApplication(): void
    {
        $directory = $this->createInputDirectory();
        $calculator = new FingerprintCalculator(new SourceFingerprint());

        try {
            $prod = $calculator->calculate($this->config($directory));
            $dev = $calculator->calculate($this->config($directory)->withAppEnvironment('dev'));

            $this->assertSame($prod->dependency, $dev->dependency);
            $this->assertNotSame($prod->application, $dev->application);
        } finally {
            (new Filesystem())->remove($directory);
        }
    }

    public function testEnvironmentOverridesInvalidateThePreparedApplication(): void
    {
        $directory = $this->createInputDirectory();
        $calculator = new FingerprintCalculator(new SourceFingerprint());

        try {
            $config = $this->config($directory);
            $initial = $calculator->calculate($config);
            $changed = $calculator->calculate($config->withEnvironmentVariables(['RECIPE_OPTION' => 'fixture']));

            $this->assertSame($initial->dependency, $changed->dependency);
            $this->assertNotSame($initial->application, $changed->application);
            $this->assertNotSame($initial->data, $changed->data);
        } finally {
            (new Filesystem())->remove($directory);
        }
    }

    public function testSimulatedOriginsRefreshTheApplicationWithoutRebuildingDependencies(): void
    {
        $directory = $this->createInputDirectory();
        $calculator = new FingerprintCalculator(new SourceFingerprint());

        try {
            $config = $this->config($directory);
            $disabled = $calculator->calculate($config);
            $enabled = $calculator->calculate($config->withSimulatedOrigins());
            $this->assertSame($disabled->dependency, $enabled->dependency);
            $this->assertNotSame($disabled->application, $enabled->application);
            $this->assertNotSame($disabled->data, $enabled->data);
            $this->assertSame($disabled->application, $calculator->calculate($config)->application);
        } finally {
            (new Filesystem())->remove($directory);
        }
    }

    public function testLinkedComposerDependenciesSelectAFreshInstallation(): void
    {
        $directory = $this->createInputDirectory();
        $filesystem = new Filesystem();
        $config = $this->config($directory);
        $calculator = new FingerprintCalculator(new SourceFingerprint());
        $cache = $config->environment->cache;
        (new WorkspaceInitializer())->initialize($cache);
        $pool = new InstallationPool();

        try {
            $initial = $calculator->calculate($config);
            $first = $pool->acquire($cache, $initial->dependency);
            $firstDirectory = $first->directory;

            try {
                $filesystem->mkdir($firstDirectory.'/project/vendor');
            } finally {
                $first->release();
            }

            $filesystem->dumpFile($directory.'/source/composer.json', '{"name":"acme/example","require":{"acme/new-editor":"^1.0"}}');
            $changed = $calculator->calculate($config);
            $second = $pool->acquire($cache, $changed->dependency);

            try {
                $this->assertNotSame($initial->dependency, $changed->dependency);
                $this->assertNotSame($firstDirectory, $second->directory);
                $this->assertDirectoryExists($firstDirectory.'/project/vendor');
                $this->assertDirectoryDoesNotExist($second->directory.'/project/vendor');
            } finally {
                $second->release();
            }
        } finally {
            $filesystem->remove($directory);
        }
    }

    public function testInferredPathPackageVersionsInvalidateDependenciesWhenTheGitBranchChanges(): void
    {
        $directory = sys_get_temp_dir().'/path-package-version-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory);

        $project = \dirname(__DIR__, 2);
        $runner = new ProcessRunner();

        try {
            // Reuse existing commits without creating any commits in the fixture repository.
            $runner->run(['git', 'clone', '--shared', '--quiet', $project, $directory.'/source'], $directory);
            $filesystem->dumpFile($directory.'/fingerprint.php', <<<'PHP'
                <?php
                require $argv[1];
                $composer = \Contao\InstallationRecipe\Composer\ComposerConfig::managedEdition('^5.7')->withOverrides([
                    'repositories' => [[
                        'type' => 'path',
                        'url' => $argv[2].'/installation-recipe',
                        'options' => ['symlink' => true],
                    ]],
                ]);
                $recipe = \Contao\InstallationRecipe\Recipe\InstallationRecipe::create($composer);
                $config = \Contao\E2eTesting\ManagedEdition\ManagedEditionConfig::create($recipe, $argv[2]);
                $calculator = new \Contao\E2eTesting\Cache\FingerprintCalculator(new \Contao\E2eTesting\Cache\SourceFingerprint());
                echo $calculator->calculate($config)->dependency;
                PHP);
            $command = [PHP_BINARY, $directory.'/fingerprint.php', $project.'/vendor/autoload.php', $directory.'/source'];
            $runner->run(['git', 'checkout', '--quiet', '-b', 'cached-version-first'], $directory.'/source');
            $first = $runner->run($command, $directory, ['COMPOSER_ROOT_VERSION' => false]);
            $firstCommit = $runner->run(['git', 'rev-parse', 'HEAD'], $directory.'/source');
            $runner->run(['git', 'checkout', '--quiet', '-b', 'cached-version-second'], $directory.'/source');
            $second = $runner->run($command, $directory, ['COMPOSER_ROOT_VERSION' => false]);

            $this->assertSame($firstCommit, $runner->run(['git', 'rev-parse', 'HEAD'], $directory.'/source'));
            $this->assertNotSame($first, $second);
        } finally {
            // Git pack files can be read-only on Windows.
            if ('\\' === \DIRECTORY_SEPARATOR && is_dir($directory.'/source/.git')) {
                $filesystem->chmod($directory.'/source/.git', 0o700, recursive: true);
            }

            $filesystem->remove($directory);
        }
    }

    private function createInputDirectory(): string
    {
        $directory = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests/fingerprint-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir([$directory, $directory.'/source']);
        $filesystem->dumpFile($directory.'/source/Example.php', '<?php return 1;');
        $filesystem->dumpFile($directory.'/source/composer.json', '{"name":"acme/example","require":{"acme/old-editor":"^1.0"}}');
        $filesystem->dumpFile($directory.'/config.yaml', "contao:\n  csrf_cookie_prefix: initial\n");
        $filesystem->dumpFile($directory.'/fixture.yaml', "example:\n  - id: 1\n");
        $filesystem->dumpFile($directory.'/tl_content.php', '<?php $GLOBALS["TL_DCA"]["tl_content"]["fields"]["example"]["eval"]["mandatory"] = false;');

        return $directory;
    }

    private function config(string $directory): ManagedEditionConfig
    {
        $composer = ComposerConfig::managedEdition('^5.7')
            ->withOverrides([
                'require' => ['acme/example' => '1.0.x-dev'],
                'repositories' => [[
                    'type' => 'path',
                    'url' => $directory.'/source',
                    'options' => ['symlink' => true, 'versions' => ['acme/example' => '1.0.x-dev']],
                ]],
            ])
            ->withOverrides(['extra' => ['example' => false]])
        ;

        $recipe = InstallationRecipe::create($composer)
            ->withConfigFile($directory.'/config.yaml')
            ->withFixtureFile($directory.'/fixture.yaml')
        ;

        return ManagedEditionConfig::create($recipe, $directory)->withDcaFile($directory.'/tl_content.php');
    }
}
