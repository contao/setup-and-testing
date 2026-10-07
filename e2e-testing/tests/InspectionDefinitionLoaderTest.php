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

use Contao\E2eTesting\Inspection\InspectionDefinitionLoader;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class InspectionDefinitionLoaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/inspection-definition-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testLoadsAConfigurationWithoutStartingServices(): void
    {
        $file = $this->file(<<<'PHP'
            <?php
            use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
            use Contao\InstallationRecipe\Composer\ComposerConfig;
            use Contao\InstallationRecipe\Recipe\InstallationRecipe;
            return ManagedEditionConfig::create(InstallationRecipe::create(ComposerConfig::managedEdition('^6.0')), __DIR__);
            PHP);

        $config = (new InspectionDefinitionLoader())->load($file);

        $this->assertInstanceOf(ManagedEditionConfig::class, $config);
        $directory = realpath($this->directory);
        $this->assertIsString($directory);
        $this->assertSame(Path::canonicalize($directory), $config->environment->cache->projectDirectory);
    }

    public function testLoadsAFactoryWithoutExecutingIt(): void
    {
        $file = $this->file('<?php return static fn () => throw new RuntimeException("Factory was called");');

        $this->assertInstanceOf(\Closure::class, (new InspectionDefinitionLoader())->load($file));
    }

    public function testRejectsAMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new InspectionDefinitionLoader())->load($this->directory.'/missing.php');
    }

    public function testRejectsADirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new InspectionDefinitionLoader())->load($this->directory);
    }

    public function testRejectsAnInvalidReturnValue(): void
    {
        $file = $this->file('<?php return "not a configuration";');
        $this->expectException(\InvalidArgumentException::class);
        (new InspectionDefinitionLoader())->load($file);
    }

    private function file(string $contents): string
    {
        $file = $this->directory.'/inspection.php';
        (new Filesystem())->dumpFile($file, $contents);

        return $file;
    }
}
