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

use Contao\E2eTestBundle\Cache\FingerprintCalculator;
use Contao\E2eTestBundle\Cache\SourceFingerprint;
use Contao\E2eTestBundle\ManagedEdition\ManagedEditionConfig;
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

        (new Filesystem())->dumpFile($directory.'/source/Example.php', '<?php return 2;');
        $sourceChanged = $calculator->calculate($this->config($directory));
        $this->assertSame($configChanged->dependency, $sourceChanged->dependency);
        $this->assertNotSame($configChanged->application, $sourceChanged->application);
    }

    private function createInputDirectory(): string
    {
        $directory = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests/fingerprint-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir([$directory, $directory.'/source']);
        $filesystem->dumpFile($directory.'/source/Example.php', '<?php return 1;');
        $filesystem->dumpFile($directory.'/config.yaml', "contao:\n  csrf_cookie_prefix: initial\n");
        $filesystem->dumpFile($directory.'/fixture.yaml', "example:\n  - id: 1\n");

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
        $config = ManagedEditionConfig::create($recipe, $directory);
        putenv('CONTAO_E2E_DATABASE_URL');

        return $config;
    }
}
