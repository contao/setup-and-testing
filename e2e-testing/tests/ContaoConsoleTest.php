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

use Contao\E2eTesting\Exception\ProcessFailedException;
use Contao\E2eTesting\Process\ContaoConsole;
use Contao\E2eTesting\Process\ProcessRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ContaoConsoleTest extends TestCase
{
    public function testRunUsesTheSelectedEnvironmentAndPreservesArguments(): void
    {
        $directory = sys_get_temp_dir().'/contao-e2e-console-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($directory.'/vendor/bin/contao-console', <<<'PHP'
            <?php
            echo json_encode([
                'arguments' => array_slice($argv, 1),
                'directory' => getcwd(),
                'environment' => getenv('APP_ENV'),
                'database' => getenv('DATABASE_URL'),
                'http_cache' => getenv('DISABLE_HTTP_CACHE'),
            ], JSON_THROW_ON_ERROR);
            PHP);

        try {
            $console = new ContaoConsole(new ProcessRunner(), 'dev');
            $arguments = ['app:example', 'an argument with spaces', '--option=value'];
            $output = $console->run($directory, 'mysql://localhost/test', $arguments);

            $this->assertSame(
                [
                    'arguments' => [...$arguments, '--no-interaction'],
                    'directory' => realpath($directory),
                    'environment' => 'dev',
                    'database' => 'mysql://localhost/test',
                    'http_cache' => '1',
                ],
                json_decode($output, true, flags: JSON_THROW_ON_ERROR),
            );
        } finally {
            $filesystem->remove($directory);
        }
    }

    public function testRunThrowsWhenTheCommandFails(): void
    {
        $directory = sys_get_temp_dir().'/contao-e2e-console-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($directory.'/vendor/bin/contao-console', '<?php fwrite(STDERR, "Command failed"); exit(1);');

        try {
            $this->expectException(ProcessFailedException::class);
            $this->expectExceptionMessage('Command failed');
            (new ContaoConsole(new ProcessRunner()))->run($directory, 'mysql://localhost/test', ['app:example']);
        } finally {
            $filesystem->remove($directory);
        }
    }

    public function testSetupUsesTheSelectedAppEnvironment(): void
    {
        $directory = sys_get_temp_dir().'/contao-e2e-console-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($directory.'/vendor/bin/contao-setup', '<?php file_put_contents(__DIR__."/../../environment", getenv("APP_ENV"));');

        try {
            (new ContaoConsole(new ProcessRunner(), 'dev'))->setup($directory, 'mysql://localhost/test');
            $this->assertSame('dev', file_get_contents($directory.'/environment'));

            (new ContaoConsole(new ProcessRunner()))->setup($directory, 'mysql://localhost/test');
            $this->assertSame('prod', file_get_contents($directory.'/environment'));
        } finally {
            $filesystem->remove($directory);
        }
    }
}
