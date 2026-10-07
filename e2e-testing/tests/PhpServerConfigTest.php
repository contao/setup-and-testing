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

use Contao\E2eTesting\Application\LocalApplicationConfig;
use Contao\E2eTesting\Http\PhpServerConfig;
use Contao\E2eTesting\Http\WebServerConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class PhpServerConfigTest extends TestCase
{
    public function testSettingsAreMergedThroughClones(): void
    {
        $original = new PhpServerConfig();
        $first = $original->withIniSettings(['memory_limit' => '128M', 'display_errors' => true]);
        $second = $first->withIniSettings(['memory_limit' => '256M', 'max_execution_time' => 30]);

        $this->assertSame([], $original->arguments());
        $this->assertSame(['-d', 'memory_limit="128M"', '-d', 'display_errors=1'], $first->arguments());
        $this->assertSame(['-d', 'memory_limit="256M"', '-d', 'display_errors=1', '-d', 'max_execution_time=30'], $second->arguments());
    }

    public function testOpcachePresetKeepsRevalidationAndDisablesDiskCaching(): void
    {
        $original = new PhpServerConfig();
        $enabled = $original->withOpcache();
        $disabled = $enabled->withOpcache(false);

        $this->assertSame([], $original->arguments());
        $this->assertContains('opcache.enable=1', $enabled->arguments());
        $this->assertContains('opcache.enable_cli=1', $enabled->arguments());
        $this->assertContains('opcache.validate_timestamps=1', $enabled->arguments());
        $this->assertContains('opcache.revalidate_freq=0', $enabled->arguments());
        $this->assertContains('opcache.file_cache=""', $enabled->arguments());
        $this->assertContains('opcache.file_cache_only=0', $enabled->arguments());
        $this->assertContains('opcache.enable=0', $disabled->arguments());
        $this->assertContains('opcache.enable_cli=0', $disabled->arguments());
    }

    #[DataProvider('invalidDirectiveNames')]
    public function testRejectsMalformedDirectiveNames(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PhpServerConfig())->withIniSettings([$name => true]);
    }

    public static function invalidDirectiveNames(): iterable
    {
        yield [''];
        yield ['memory_limit=128M'];
        yield ['-n'];
        yield ["opcache.enable\n"];
        yield ['section[name]'];
    }

    public function testRejectsNullBytesInValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PhpServerConfig())->withIniSettings(['error_prepend_string' => "bad\0value"]);
    }

    public function testIniValuesSurvivePhpParsing(): void
    {
        $value = 'spaces, quotes "example", semicolons; equals= backslashes\\ and ${CONTAO_INI_VALUE} $5';
        $config = (new PhpServerConfig())->withIniSettings(['error_prepend_string' => $value]);
        $process = new Process([PHP_BINARY, ...$config->arguments(), '-r', 'echo json_encode(ini_get("error_prepend_string"));']);
        $process->setEnv(['CONTAO_INI_VALUE' => 'must not expand']);
        $process->mustRun();

        $this->assertSame($value, json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testPhpSettingsAreInsertedBeforeServerArgumentsWithoutReplacingTheirValues(): void
    {
        $directory = sys_get_temp_dir().'/php-command-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory.'/public');
        $filesystem->dumpFile($directory.'/router.php', '<?php echo "OK";');

        try {
            $original = WebServerConfig::php($directory, router: 'router.php');
            $php = (new PhpServerConfig())->withIniSettings(['error_prepend_string' => '{port}']);
            $configured = $original->withPhpServer($php);
            $command = $original->commandForPort(1234);
            $this->assertSame([$command[0], '-d', 'error_prepend_string="{port}"', ...\array_slice($command, 1)], $configured->commandForPort(1234));
            $this->assertSame($command, $original->commandForPort(1234));
            $this->assertSame([...\array_slice($configured->commandForPort(1234), 0, 3), '-d', 'opcache.cache_id="isolated"', ...\array_slice($command, 1)], $configured->commandForPort(1234, 'isolated'));
        } finally {
            $filesystem->remove($directory);
        }
    }

    public function testCustomCommandsRejectPhpConfiguration(): void
    {
        $config = LocalApplicationConfig::command(['node', 'server.js', '{port}'], sys_get_temp_dir());
        $this->expectException(\InvalidArgumentException::class);
        $config->withPhpServer((new PhpServerConfig())->withOpcache());
    }
}
