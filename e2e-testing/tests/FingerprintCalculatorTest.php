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
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class FingerprintCalculatorTest extends TestCase
{
    public function testSeparatesDependencyApplicationAndDataChanges(): void
    {
        $directory = $this->createInputDirectory();
        $calculator = new FingerprintCalculator(new SourceFingerprint());
        $initial = $calculator->calculate($this->config($directory));

        (new Filesystem())->dumpFile($directory.'/fixture.yaml', "example:\n  - id: 2\n");
        $fixtureChanged = $calculator->calculate($this->config($directory));
        $this->assertSame($initial->dependency, $fixtureChanged->dependency);
        $this->assertSame($initial->application, $fixtureChanged->application);
        $this->assertNotSame($initial->data, $fixtureChanged->data);

        (new Filesystem())->dumpFile($directory.'/config.yaml', "contao:\n  csrf_cookie_prefix: changed\n");
        $configChanged = $calculator->calculate($this->config($directory));
        $this->assertSame($initial->dependency, $configChanged->dependency);
        $this->assertNotSame($initial->application, $configChanged->application);

        (new Filesystem())->dumpFile($directory.'/tl_content.php', '<?php $GLOBALS["TL_DCA"]["tl_content"]["fields"]["example"]["eval"]["mandatory"] = true;');
        $dcaChanged = $calculator->calculate($this->config($directory));
        $this->assertSame($configChanged->dependency, $dcaChanged->dependency);
        $this->assertNotSame($configChanged->application, $dcaChanged->application);

        (new Filesystem())->dumpFile($directory.'/source/Example.php', '<?php return 2;');
        $sourceChanged = $calculator->calculate($this->config($directory));
        $this->assertSame($dcaChanged->dependency, $sourceChanged->dependency);
        $this->assertNotSame($dcaChanged->application, $sourceChanged->application);
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
        $calculator = new FingerprintCalculator();
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
            ->withPathPackage('acme/example', $directory.'/source', '1.0.x-dev')
        ;

        $recipe = InstallationRecipe::create($composer)
            ->withConfigFile($directory.'/config.yaml')
            ->withFixtureFile($directory.'/fixture.yaml')
        ;
        putenv('CONTAO_E2E_DATABASE_URL=mysql://root@127.0.0.1');
        $config = ManagedEditionConfig::create($recipe, $directory)->withDcaFile($directory.'/tl_content.php');
        putenv('CONTAO_E2E_DATABASE_URL');

        return $config;
    }
}
