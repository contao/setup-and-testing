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

use Contao\InstallationRecipe\Exception\InvalidRecipeException;
use Contao\InstallationRecipe\File\FileMapping;
use Contao\InstallationRecipe\File\PortableFilePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class PortableFilePolicyTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/recipe-policy-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory);
        (new Filesystem())->dumpFile($this->directory.'/style.css', 'body {}');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    #[DataProvider('protectedTargets')]
    public function testRejectsProtectedTargets(string $target): void
    {
        $this->expectException(InvalidRecipeException::class);
        (new PortableFilePolicy())->validate(new FileMapping($this->directory.'/style.css', $target));
    }

    public static function protectedTargets(): iterable
    {
        yield ['composer.json'];
        yield ['composer.lock'];
        yield ['config/config.yaml'];
        yield ['config'];
        yield ['vendor/style.css'];
        yield ['.git/config'];
        yield ['.contao-recipes/recipe.json'];
        yield ['files/./'];
        yield ['files//theme/style.css'];
    }

    public function testAllowsApplicationConfigurationAndTemplates(): void
    {
        $policy = new PortableFilePolicy();
        $policy->validate(new FileMapping($this->directory.'/style.css', 'contao/dca/tl_content.php'));
        $policy->validate(new FileMapping($this->directory.'/style.css', 'config/services.php'));
        $policy->validate(new FileMapping($this->directory.'/style.css', 'templates/page.html5'));
        $policy->validate(new FileMapping($this->directory.'/style.css', 'public/.htaccess'));
        $this->assertNull($policy->allowedTargets);
    }

    public function testHostCanLimitDestinationsWithoutMutatingTheDefaultPolicy(): void
    {
        $policy = new PortableFilePolicy();
        $limited = $policy->withAllowedTargets(['contao/dca', 'config/services.php']);
        $limited->validate(new FileMapping($this->directory.'/style.css', 'contao/dca/tl_content.php'));
        $limited->validate(new FileMapping($this->directory.'/style.css', 'config/services.php'));
        $this->assertNull($policy->allowedTargets);
        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('host has not allowed');
        $limited->validate(new FileMapping($this->directory.'/style.css', 'config/packages/security.php'));
    }

    public function testHostCanChooseItsProtectedTargets(): void
    {
        $default = new PortableFilePolicy();
        $managedEdition = $default->withProtectedTargets(['vendor']);
        $managedEdition->validate(new FileMapping($this->directory.'/style.css', 'config/config.yaml'));
        $this->assertContains('config/config.yaml', $default->protectedTargets);
        $this->expectException(InvalidRecipeException::class);
        $managedEdition->validate(new FileMapping($this->directory.'/style.css', 'vendor/style.css'));
    }

    public function testOverwriteNeedsHostPermission(): void
    {
        $mapping = new FileMapping($this->directory.'/style.css', 'files/theme/style.css', true);
        $policy = new PortableFilePolicy();
        $allowed = $policy->withOverwrite(true);
        $allowed->validate($mapping);
        $this->assertFalse($policy->overwrite);
        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('host must explicitly allow');
        $policy->validate($mapping);
    }
}
