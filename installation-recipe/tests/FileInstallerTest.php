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
use Contao\InstallationRecipe\File\FileInstaller;
use Contao\InstallationRecipe\File\FileMapping;
use Contao\InstallationRecipe\File\PortableFilePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class FileInstallerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/recipe-files-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir([$this->directory.'/project', $this->directory.'/outside', $this->directory.'/source']);
        (new Filesystem())->dumpFile($this->directory.'/source/style.css', 'body {}');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    #[DataProvider('symbolicLinks')]
    public function testRejectsDestinationSymbolicLinksOutsideTheInstallation(string $link, string $target): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir(\dirname($this->directory.'/project/'.$link));
        $this->symlink($this->directory.'/outside', $this->directory.'/project/'.$link);

        try {
            (new FileInstaller())->install(
                [new FileMapping($this->directory.'/source', $target, true)],
                $this->directory.'/project',
            );
            $this->fail('A destination outside the installation must be rejected.');
        } catch (InvalidRecipeException $exception) {
            $this->assertStringContainsString('outside', $exception->getMessage());
            $this->assertFileDoesNotExist($this->directory.'/outside/style.css');
        }
    }

    public static function symbolicLinks(): iterable
    {
        yield 'parent' => ['files', 'files/theme'];
        yield 'mapped directory' => ['files/theme', 'files/theme'];
        yield 'nested entry' => ['files/theme/style.css', 'files/theme'];
    }

    public function testRejectsSourceSymbolicLinks(): void
    {
        $this->symlink($this->directory.'/outside', $this->directory.'/source/linked');
        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('symbolic links');
        (new FileInstaller())->install([new FileMapping($this->directory.'/source', 'files/theme')], $this->directory.'/project');
    }

    public function testCopiesAnAssetDirectory(): void
    {
        (new FileInstaller())->install([new FileMapping($this->directory.'/source', 'files/theme')], $this->directory.'/project');
        $this->assertSame('body {}', file_get_contents($this->directory.'/project/files/theme/style.css'));
    }

    public function testOverwritingAHardLinkPreservesTheOutsideFile(): void
    {
        $outside = $this->directory.'/outside/key';
        $destination = $this->directory.'/project/style.css';
        (new Filesystem())->dumpFile($outside, 'original');

        if (!@link($outside, $destination)) {
            $this->markTestSkipped('Hard links are unavailable on this platform.');
        }

        (new FileInstaller())->install([new FileMapping($this->directory.'/source/style.css', 'style.css', true)], $this->directory.'/project');
        $this->assertSame('original', file_get_contents($outside));
        $this->assertSame('body {}', file_get_contents($destination));
    }

    #[DataProvider('parentComponentLinks')]
    public function testResolvesParentComponentsAfterDirectoryLinks(bool $absolute, bool $existing): void
    {
        $filesystem = new Filesystem();
        $project = $this->directory.'/project';
        $filesystem->mkdir($project.'/real/child');
        $filesystem->dumpFile($project.'/style.css', 'unrelated');

        if ($existing) {
            $filesystem->dumpFile($project.'/real/style.css', 'old');
        }

        $this->symlink('real/child', $project.'/alias');
        $this->symlink(($absolute ? $project.'/' : '').'alias/../style.css', $project.'/mapped.css');
        (new FileInstaller())->install([new FileMapping($this->directory.'/source/style.css', 'mapped.css', true)], $project);
        $this->assertSame('body {}', file_get_contents($project.'/real/style.css'));
        $this->assertSame('body {}', file_get_contents($project.'/mapped.css'));
        $this->assertSame('unrelated', file_get_contents($project.'/style.css'));
        $this->assertTrue(is_link($project.'/mapped.css'));
    }

    public static function parentComponentLinks(): iterable
    {
        yield 'relative existing' => [false, true];
        yield 'relative missing' => [false, false];
        yield 'absolute existing' => [true, true];
        yield 'absolute missing' => [true, false];
    }

    public function testRejectsParentComponentsResolvingOutsideTheInstallation(): void
    {
        (new Filesystem())->mkdir($this->directory.'/outside/child');
        $this->symlink('../outside/child', $this->directory.'/project/alias');
        $this->symlink('alias/../style.css', $this->directory.'/project/mapped.css');
        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('outside');
        (new FileInstaller())->install([new FileMapping($this->directory.'/source/style.css', 'mapped.css')], $this->directory.'/project');
    }

    public function testRejectsParentComponentsAfterMissingDirectories(): void
    {
        $this->symlink('missing/../style.css', $this->directory.'/project/mapped.css');
        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('existing directory');
        (new FileInstaller())->install([new FileMapping($this->directory.'/source/style.css', 'mapped.css')], $this->directory.'/project');
    }

    public function testParentComponentsCannotBypassProtectedTargets(): void
    {
        (new Filesystem())->mkdir($this->directory.'/project/vendor/child');
        $this->symlink('vendor/child', $this->directory.'/project/alias');
        $this->symlink('alias/../style.css', $this->directory.'/project/mapped.css');
        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('protected');
        (new FileInstaller())->withPolicy(new PortableFilePolicy())->install(
            [new FileMapping($this->directory.'/source/style.css', 'mapped.css')],
            $this->directory.'/project',
        );
    }

    #[DataProvider('ambiguousPaths')]
    public function testRejectsAmbiguousWindowsDestinations(string $target): void
    {
        $this->expectException(InvalidRecipeException::class);
        (new FileInstaller())->install(
            [new FileMapping($this->directory.'/source/style.css', $target)],
            $this->directory.'/project',
        );
    }

    public static function ambiguousPaths(): iterable
    {
        yield ['vendor./autoload.php'];
        yield ['vendor /autoload.php'];
        yield ['files/NUL.css'];
        yield ['files/style.css:stream'];
    }

    #[DataProvider('internalLinks')]
    public function testCopiesThroughInternalDirectoryLinks(string $link, string $target, string $resolved): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir([$this->directory.'/project/real/theme', \dirname($this->directory.'/project/'.$link)]);

        $destination = $this->directory.'/project/real/theme';
        $this->symlink($destination, $this->directory.'/project/'.$link);
        (new FileInstaller())->install([new FileMapping($this->directory.'/source', $target, true)], $this->directory.'/project');
        $this->assertSame('body {}', file_get_contents($this->directory.'/project/'.$resolved));
        $this->assertTrue(is_link($this->directory.'/project/'.$link));
    }

    public static function internalLinks(): iterable
    {
        yield 'parent' => ['files', 'files/nested', 'real/theme/nested/style.css'];
        yield 'directory itself' => ['files/theme', 'files/theme', 'real/theme/style.css'];
    }

    public function testCopiesThroughNestedFileLinks(): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->directory.'/project/real/style.css', 'old');
        $filesystem->mkdir($this->directory.'/project/files/theme');
        $this->symlink('../../real/style.css', $this->directory.'/project/files/theme/style.css');
        (new FileInstaller())->install([new FileMapping($this->directory.'/source', 'files/theme', true)], $this->directory.'/project');
        $this->assertSame('body {}', file_get_contents($this->directory.'/project/real/style.css'));
        $this->assertTrue(is_link($this->directory.'/project/files/theme/style.css'));
    }

    public function testResolvesRelativeChainsToMissingDestinations(): void
    {
        $this->symlink('second', $this->directory.'/project/first');
        $this->symlink('real/not-created', $this->directory.'/project/second');
        (new FileInstaller())->install([new FileMapping($this->directory.'/source/style.css', 'first/style.css')], $this->directory.'/project');
        $this->assertSame('body {}', file_get_contents($this->directory.'/project/real/not-created/style.css'));
    }

    public function testRejectsCyclicLinks(): void
    {
        $this->symlink('second', $this->directory.'/project/first');
        $this->symlink('first', $this->directory.'/project/second');
        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('too many symbolic links');
        (new FileInstaller())->install([new FileMapping($this->directory.'/source', 'first/theme')], $this->directory.'/project');
    }

    public function testInternalLinkCannotBypassProtectedTargets(): void
    {
        (new Filesystem())->mkdir($this->directory.'/project/vendor');
        $this->symlink('vendor', $this->directory.'/project/files');
        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('protected');
        (new FileInstaller())->withPolicy((new PortableFilePolicy())->withOverwrite(true))->install(
            [new FileMapping($this->directory.'/source', 'files/theme', true)],
            $this->directory.'/project',
        );
    }

    public function testInternalLinkCannotBypassAllowedTargets(): void
    {
        $this->symlink('uploads', $this->directory.'/project/files');
        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('host has not allowed');
        (new FileInstaller())->withPolicy((new PortableFilePolicy())->withAllowedTargets(['files']))->install(
            [new FileMapping($this->directory.'/source/style.css', 'files/theme/style.css')],
            $this->directory.'/project',
        );
    }

    #[DataProvider('protectedLinks')]
    public function testProtectsPhysicalDestinationsOfSymbolicLinks(string $link, string $resolved, string $target): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir(\dirname($this->directory.'/project/'.$link));
        $this->symlink($resolved, $this->directory.'/project/'.$link);
        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('protected');
        (new FileInstaller())->withPolicy(new PortableFilePolicy())->install(
            [new FileMapping($this->directory.'/source/style.css', $target)],
            $this->directory.'/project',
        );
    }

    public static function protectedLinks(): iterable
    {
        yield 'dependencies' => ['vendor', 'dependencies', 'dependencies/style.css'];
        yield 'Composer metadata' => ['composer.json', 'metadata/composer.json', 'metadata/composer.json'];
        yield 'configuration directory' => ['config', 'application-config', 'application-config/config.yaml'];
        yield 'configuration file' => ['config/config.yaml', '../application-config/theme.yaml', 'application-config/theme.yaml'];
        yield 'journals' => ['.contao-recipes', 'journals', 'journals/style.css'];
        yield 'Git metadata' => ['.git', 'metadata/git', 'metadata/git/style.css'];
        yield 'installation root' => ['vendor', '.', 'style.css'];
    }

    public function testRejectsDirectoryMappingsIntoResolvedProtectedTargets(): void
    {
        $this->symlink('dependencies', $this->directory.'/project/vendor');
        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('protected');
        (new FileInstaller())->withPolicy(new PortableFilePolicy())->install(
            [new FileMapping($this->directory.'/source', 'dependencies/theme')],
            $this->directory.'/project',
        );
    }

    public function testExternalProtectedLinksDoNotBlockUnrelatedFiles(): void
    {
        $this->symlink($this->directory.'/outside', $this->directory.'/project/vendor');
        (new FileInstaller())->withPolicy(new PortableFilePolicy())->install(
            [new FileMapping($this->directory.'/source/style.css', 'style.css')],
            $this->directory.'/project',
        );
        $this->assertSame('body {}', file_get_contents($this->directory.'/project/style.css'));
        $this->assertFileDoesNotExist($this->directory.'/outside/style.css');
    }

    public function testHostCanAllowResolvedDocumentDestinations(): void
    {
        (new Filesystem())->dumpFile($this->directory.'/project/metadata/composer.json', '{}');
        $this->symlink('metadata/composer.json', $this->directory.'/project/composer.json');
        $policy = (new PortableFilePolicy())->withProtectedTargets(['vendor'])->withOverwrite(true);
        (new FileInstaller())->withPolicy($policy)->install(
            [new FileMapping($this->directory.'/source/style.css', 'metadata/composer.json', true)],
            $this->directory.'/project',
        );
        $this->assertSame('body {}', file_get_contents($this->directory.'/project/composer.json'));
    }

    public function testRechecksProtectedLinksRetargetedByAnotherProcess(): void
    {
        $project = $this->directory.'/project';
        (new Filesystem())->mkdir([$project.'/first', $project.'/second']);
        $this->symlink('first', $project.'/vendor');
        $policy = new PortableFilePolicy();
        $policy->forInstallation($project)->validateTarget('second/style.css', false);
        (new Process([
            PHP_BINARY,
            '-r',
            'unlink($argv[1]); symlink("second", $argv[1]);',
            $project.'/vendor',
        ]))->mustRun();
        $this->expectException(InvalidRecipeException::class);
        $this->expectExceptionMessage('protected');
        (new FileInstaller())->withPolicy($policy)->install(
            [new FileMapping($this->directory.'/source/style.css', 'second/style.css')],
            $project,
        );
    }

    private function symlink(string $source, string $target): void
    {
        if (!@symlink($source, $target)) {
            $this->markTestSkipped('Symbolic links are unavailable on this platform.');
        }
    }
}
