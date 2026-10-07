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

use Contao\E2eTesting\Command\E2eApplication;
use Contao\E2eTesting\Command\ServerStartCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class ServerStartCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/inspection command '.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testRegistersTheCommand(): void
    {
        $this->assertTrue((new E2eApplication())->has('server:start'));
    }

    public function testRejectsAnInvalidFactoryResult(): void
    {
        $file = $this->directory.'/invalid.php';
        (new Filesystem())->dumpFile($file, '<?php return static fn ($runtime) => "invalid";');
        $this->expectException(\InvalidArgumentException::class);
        (new CommandTester(new ServerStartCommand()))->execute(['inspection-file' => $file]);
    }

    #[DataProvider('inputModes')]
    public function testKeepsThePreparedStateAndLeasesAliveUntilInputStops(bool $sendEnter): void
    {
        $input = new InputStream();
        $process = $this->process();
        $process->setInput($input);
        $process->start();

        try {
            $url = $this->waitForEdition($process);
            $this->assertSame('Prepared inspection state', HttpClient::create()->request('GET', $url)->getContent());
            $this->assertStringContainsString('mysql://localhost/inspection', $process->getOutput());
            $this->assertFalse(is_file($this->directory.'/database-stopped'));
            $this->assertInstallationLocked();
            if ($sendEnter) {
                $input->write("\n");
            }

            $input->close();
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
            $this->assertSessionReleased($url);
        } finally {
            $process->stop();
        }
    }

    public static function inputModes(): iterable
    {
        yield 'Enter' => [true];
        yield 'closed input' => [false];
    }

    #[DataProvider('signalModes')]
    public function testSignalShutdownReleasesTheSession(bool $interactive, string $signal): void
    {
        if (!\function_exists('pcntl_signal')) {
            $this->markTestSkipped('Signal handling requires PCNTL. Use Enter on Windows.');
        }

        $process = $this->process(interactive: $interactive);
        $process->setInput(new InputStream());
        $process->start();

        try {
            $url = $this->waitForEdition($process);
            $process->signal('interrupt' === $signal ? SIGINT : SIGTERM);
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
            $this->assertSessionReleased($url);
        } finally {
            $process->stop();
        }
    }

    public static function signalModes(): iterable
    {
        yield 'interactive termination' => [true, 'terminate'];
        yield 'interactive interrupt' => [true, 'interrupt'];
        yield 'non-interactive termination' => [false, 'terminate'];
    }

    public function testFailedPreparationReleasesTheSession(): void
    {
        $file = $this->directory.'/failed.php';
        $factory = var_export(__DIR__.'/Fixtures/inspection.php', true);
        (new Filesystem())->dumpFile($file, '<?php return static function ($runtime) {
            $factory = require '.$factory.';
            $edition = $factory($runtime)->startServer();
            file_put_contents(getenv("CONTAO_INSPECTION_TEST_DIRECTORY")."/url", $edition->uri());
            throw new RuntimeException("Inspection stage failed");
        };');
        $process = $this->process($file);
        $this->assertSame(1, $process->run());
        $this->assertStringContainsString('Inspection stage failed', $process->getOutput().$process->getErrorOutput());
        $this->assertSessionReleased(file_get_contents($this->directory.'/url'));
    }

    public function testInterruptingPreparationReleasesTheSession(): void
    {
        if (!\function_exists('pcntl_signal')) {
            $this->markTestSkipped('Interrupting preparation requires PCNTL.');
        }

        $file = $this->directory.'/slow.php';
        $factory = var_export(__DIR__.'/Fixtures/inspection.php', true);
        (new Filesystem())->dumpFile($file, '<?php return static function ($runtime) {
            $factory = require '.$factory.';
            $edition = $factory($runtime)->startServer();
            file_put_contents(getenv("CONTAO_INSPECTION_TEST_DIRECTORY")."/url", $edition->uri());
            fwrite(STDOUT, "Preparing inspection");
            sleep(30);
            return $edition;
        };');
        $process = $this->process($file);
        $process->start();

        try {
            $this->assertTrue($process->waitUntil(static fn (): bool => str_contains($process->getOutput(), 'Preparing inspection')));
            $process->signal(SIGINT);
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
            $this->assertSessionReleased(file_get_contents($this->directory.'/url'));
        } finally {
            $process->stop();
        }
    }

    public function testRejectsAnEditionUsingAnotherRuntimeAndReleasesIt(): void
    {
        $file = $this->directory.'/foreign-runtime.php';
        $factory = var_export(__DIR__.'/Fixtures/inspection.php', true);
        (new Filesystem())->dumpFile($file, '<?php return static function ($runtime) {
            $factory = require '.$factory.';
            $edition = $factory(Contao\\E2eTesting\\Application\\ApplicationRuntime::create())->startServer();
            file_put_contents(getenv("CONTAO_INSPECTION_TEST_DIRECTORY")."/url", $edition->uri());
            return $edition;
        };');
        $process = $this->process($file);

        $this->assertSame(1, $process->run());
        $this->assertStringContainsString('must use the supplied ApplicationRuntime', $process->getOutput().$process->getErrorOutput());
        $this->assertSessionReleased(file_get_contents($this->directory.'/url'));
    }

    private function process(string|null $file = null, bool $interactive = true): Process
    {
        $bootstrap = $this->directory.'/console.php';
        (new Filesystem())->dumpFile($bootstrap, '<?php $GLOBALS["_composer_autoload_path"] = '.var_export(\dirname(__DIR__, 2).'/vendor/autoload.php', true).'; require '.var_export(\dirname(__DIR__).'/bin/contao-e2e', true).';');
        $command = [PHP_BINARY, $bootstrap, 'server:start', $file ?? __DIR__.'/Fixtures/inspection.php'];

        if (!$interactive) {
            $command[] = '--no-interaction';
        }

        $process = new Process($command);
        $process->setEnv(['CONTAO_INSPECTION_TEST_DIRECTORY' => $this->directory]);
        $process->setTimeout(20);

        return $process;
    }

    private function waitForEdition(Process $process): string
    {
        $this->assertTrue($process->waitUntil(static fn (): bool => str_contains($process->getOutput(), 'Inspection session is running.')), $process->getErrorOutput());
        $this->assertSame(1, preg_match('~http://localhost:\d+/~', $process->getOutput(), $matches));

        return $matches[0];
    }

    private function assertInstallationLocked(): void
    {
        $lock = fopen($this->directory.'/installation.lock', 'c+');

        try {
            $this->assertFalse(flock($lock, LOCK_EX | LOCK_NB));
        } finally {
            fclose($lock);
        }
    }

    private function assertSessionReleased(string $url): void
    {
        $lock = fopen($this->directory.'/installation.lock', 'c+');

        try {
            $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        } finally {
            fclose($lock);
        }

        $this->assertSame('stopped', file_get_contents($this->directory.'/database-stopped'));
        $socket = @stream_socket_client(str_replace('http://localhost:', 'tcp://127.0.0.1:', rtrim($url, '/')), $code, $message, 0.1);

        if (false !== $socket) {
            fclose($socket);
        }

        $this->assertFalse($socket, 'The inspection HTTP server must stop with the session.');
    }
}
