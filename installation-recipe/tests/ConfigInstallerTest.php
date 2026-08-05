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

use Contao\InstallationRecipe\Configuration\ConfigFragment;
use Contao\InstallationRecipe\Configuration\ConfigInstaller;
use Contao\InstallationRecipe\Configuration\ConfigMerger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class ConfigInstallerTest extends TestCase
{
    public function testImportsTheBaseConfigurationBeforeRecipeFragments(): void
    {
        $directory = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests/config-'.bin2hex(random_bytes(6));
        $fragment = $directory.'/fragment.yaml';
        $baseConfig = $directory.'/base.yaml';
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory);
        $filesystem->dumpFile($baseConfig, "framework: ~\n");
        $filesystem->dumpFile($fragment, "contao:\n  csrf_cookie_prefix: test\n");

        (new ConfigInstaller())->install([new ConfigFragment($fragment)], $directory, $baseConfig);

        $config = Yaml::parseFile($directory.'/config/config.yaml');
        $this->assertSame($baseConfig, $config['imports'][0]['resource']);
        $this->assertSame('contao-e2e/00-fragment.yaml', $config['imports'][1]['resource']);
    }

    public function testMergesMappingsAndReplacesLists(): void
    {
        $directory = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests/config-merge-'.bin2hex(random_bytes(6));
        $fragment = $directory.'/fragment.yaml';
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory.'/config');
        $filesystem->dumpFile($directory.'/config/config.yaml', "framework:\n  secret: Existing\n  trusted_hosts: [one, two]\n");
        $filesystem->dumpFile($fragment, "framework:\n  csrf_protection: false\n  trusted_hosts: [replacement]\n");

        $this->assertTrue((new ConfigMerger())->merge([new ConfigFragment($fragment)], $directory));

        $config = Yaml::parseFile($directory.'/config/config.yaml');
        $this->assertSame('Existing', $config['framework']['secret']);
        $this->assertFalse($config['framework']['csrf_protection']);
        $this->assertSame(['replacement'], $config['framework']['trusted_hosts']);
    }
}
