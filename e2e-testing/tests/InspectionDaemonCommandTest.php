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

use Contao\E2eTesting\Application\ApplicationRuntime;
use Contao\E2eTesting\Application\LocalApplicationConfig;
use Contao\E2eTesting\Command\E2eApplication;
use Contao\E2eTesting\Database\DockerDatabaseLease;
use Contao\E2eTesting\Inspection\InspectionSessionStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;

final class InspectionDaemonCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/inspection daemon '.bin2hex(random_bytes(6));
        $autoload = var_export(\dirname(__DIR__, 2).'/vendor/autoload.php', true);
        $bin = var_export(\dirname(__DIR__).'/bin/contao-e2e', true);
        (new Filesystem())->dumpFile($this->directory.'/console.php', '<?php $GLOBALS["_composer_autoload_path"] = '.$autoload.'; require '.$bin.';');
    }

    protected function tearDown(): void
    {
        $stop = $this->command(['server:stop']);
        $this->assertSame(0, $stop->run(), $stop->getOutput().$stop->getErrorOutput());
        (new Filesystem())->remove($this->directory);
    }

    public function testRegistersCommandsAndReportsIdleState(): void
    {
        $application = new E2eApplication();
        $this->assertTrue($application->has('server:stop'));
        $this->assertTrue($application->has('server:status'));
        $status = $this->command(['server:status']);
        $this->assertSame(0, $status->run());
        $this->assertStringContainsString('stopped', $status->getOutput());
        $stop = $this->command(['server:stop']);
        $this->assertSame(0, $stop->run());
        $this->assertStringContainsString('No background', $stop->getOutput());
        $this->assertDirectoryDoesNotExist($this->directory.'/.contao-e2e');
    }

    public function testDetachedSessionKeepsStateAndLeasesUntilStop(): void
    {
        $this->start();
        $state = $this->waitForPhase('running');
        $url = (string) $state['url'];
        $this->assertSame(rtrim($url, '/').'/contao', $state['backend']);
        $this->assertSame('mysql://localhost/inspection', $state['database']);
        $this->assertSame('Prepared inspection state', HttpClient::create()->request('GET', $url)->getContent());
        $this->assertFalse(is_file($this->directory.'/database-stopped'));
        $this->assertInstallationLock(false);
        $status = $this->command(['server:status']);
        $this->assertSame(0, $status->run());
        $this->assertStringContainsString($url, $status->getOutput());
        $duplicate = $this->command(['server:start', __DIR__.'/Fixtures/inspection.php', '-d']);
        $this->assertSame(1, $duplicate->run());
        $this->assertStringContainsString('already running', $duplicate->getOutput().$duplicate->getErrorOutput());
        $stop = $this->command(['server:stop']);
        $this->assertSame(0, $stop->run(), $stop->getErrorOutput());
        $this->assertFalse($this->store()->isActive());
        $this->assertSame('stopped', $this->store()->read()['phase']);
        $this->assertInstallationLock(true);
        $this->assertSame('stopped', file_get_contents($this->directory.'/database-stopped'));
        $this->assertServerStopped($url);
        $this->assertSame(0, $this->command(['server:stop'])->run());
    }

    #[DataProvider('localModes')]
    public function testBackgroundInspectionSupportsLocalApplications(string $mode): void
    {
        $this->start(__DIR__.'/Fixtures/inspection-local.php', ['CONTAO_INSPECTION_TEST_MODE' => $mode]);
        $state = $this->waitForPhase('running');
        $url = (string) $state['url'];
        $expected = 'prepared' === $mode ? 'Prepared local inspection state' : 'Local inspection state';
        $this->assertSame($expected, HttpClient::create()->request('GET', $url)->getContent());
        $this->assertArrayNotHasKey('backend', $state);
        $this->assertArrayNotHasKey('directory', $state);
        $this->assertArrayNotHasKey('database', $state);
        $status = $this->command(['server:status']);
        $this->assertSame(0, $status->run());
        $this->assertStringContainsString($url, $status->getOutput());
        $this->assertStringNotContainsString('Backend', $status->getOutput());
        $this->assertSame(0, $this->command(['server:stop'])->run());
        $this->assertServerStopped($url);
    }

    #[DataProvider('failureModes')]
    public function testLocalPreparationFailureReleasesTheBackgroundSession(string $mode, string $message): void
    {
        $this->command(['server:start', __DIR__.'/Fixtures/inspection-local.php', '-d'], ['CONTAO_INSPECTION_TEST_MODE' => $mode])->run();
        $this->waitForPhase('failed');
        $this->waitForExit();
        $this->assertServerStopped(file_get_contents($this->directory.'/url'));
        $log = file_get_contents($this->store()->logFile());

        if (is_file($this->store()->logFile().'.error')) {
            $log .= file_get_contents($this->store()->logFile().'.error');
        }

        $this->assertStringContainsString($message, $log);
        $this->assertSame(1, $this->command(['server:status'])->run());
        $this->start(__DIR__.'/Fixtures/inspection-local.php');
        $this->waitForPhase('running');
    }

    public static function failureModes(): iterable
    {
        yield 'factory throws' => ['failed', 'Local inspection preparation failed'];
        yield 'foreign runtime' => ['foreign', 'must use the supplied ApplicationRuntime'];
    }

    public static function localModes(): iterable
    {
        yield 'PHP server' => ['php'];
        yield 'custom command' => ['command'];
        yield 'prepared application' => ['prepared'];
        yield 'configuration factory' => ['factory'];
    }

    public function testBackgroundInspectionDoesNotStopAnExternalServer(): void
    {
        (new Filesystem())->dumpFile($this->directory.'/public/index.php', '<?php echo "External application";');
        $runtime = ApplicationRuntime::create();
        $external = $runtime->createApplication(LocalApplicationConfig::php($this->directory));

        try {
            $this->start(__DIR__.'/Fixtures/inspection-local.php', [
                'CONTAO_INSPECTION_TEST_MODE' => 'external',
                'CONTAO_INSPECTION_TEST_URL' => $external->uri(),
            ]);
            $this->assertSame($external->uri(), $this->waitForPhase('running')['url']);
            $this->assertSame(0, $this->command(['server:stop'])->run());
            $this->assertSame('External application', HttpClient::create()->request('GET', $external->uri())->getContent());
        } finally {
            $external->release();
            $runtime->close();
        }
    }

    public function testStopPreservesADatabaseUsedByAnotherProcess(): void
    {
        $lease = DockerDatabaseLease::acquire(
            $this->directory.'/database.lock',
            fn () => file_put_contents($this->directory.'/database-stopped', 'stopped'),
        );

        try {
            $this->start();
            $this->waitForPhase('running');
            $this->assertSame(0, $this->command(['server:stop'])->run());
            $this->assertFalse(is_file($this->directory.'/database-stopped'));
            $this->assertInstallationLock(true);
        } finally {
            $lease->release();
        }

        $this->assertSame('stopped', file_get_contents($this->directory.'/database-stopped'));
    }

    public function testCanRestartDespiteAnOldStopRequest(): void
    {
        $this->start();
        $this->waitForPhase('running');
        $first = $this->store()->read()['token'];
        $this->assertSame(0, $this->command(['server:stop'])->run());
        $this->start();
        $this->waitForPhase('running');
        $this->assertNotSame($first, $this->store()->read()['token']);
        $this->assertTrue($this->store()->isActive());
    }

    public function testRecordsFailureAndAllowsRestart(): void
    {
        $file = $this->directory.'/failed.php';
        $factory = var_export(__DIR__.'/Fixtures/inspection.php', true);
        (new Filesystem())->dumpFile($file, '<?php return static function ($runtime) {
            $factory = require '.$factory.';
            $edition = $factory($runtime)->startServer();
            throw new RuntimeException("Inspection stage failed");
        };');
        $this->command(['server:start', $file, '-d'])->run();
        $this->waitForPhase('failed');
        $this->waitForExit();
        $this->assertInstallationLock(true);
        $this->assertSame('stopped', file_get_contents($this->directory.'/database-stopped'));
        $status = $this->command(['server:status']);
        $this->assertSame(1, $status->run());
        $this->assertStringContainsString('failed', $status->getOutput());
        $log = file_get_contents($this->store()->logFile());
        if (is_file($this->store()->logFile().'.error')) {
            $log .= file_get_contents($this->store()->logFile().'.error');
        }
        $this->assertStringContainsString('Inspection stage failed', $log);
        $this->start();
        $this->waitForPhase('running');
    }

    public function testStopDuringPreparationCleansUp(): void
    {
        if (!\function_exists('pcntl_signal') || !\function_exists('posix_kill')) {
            $this->markTestSkipped('Interrupting preparation requires PCNTL and POSIX.');
        }

        $file = $this->directory.'/slow.php';
        $factory = var_export(__DIR__.'/Fixtures/inspection.php', true);
        (new Filesystem())->dumpFile($file, '<?php return static function ($runtime) {
            $factory = require '.$factory.';
            $edition = $factory($runtime)->startServer();
            file_put_contents(getenv("CONTAO_INSPECTION_TEST_DIRECTORY")."/preparing", "yes");
            sleep(30);
            return $edition;
        };');
        $this->start($file);
        $this->assertSame('starting', $this->store()->read()['phase']);
        $this->waitFor(fn (): bool => is_file($this->directory.'/preparing'));
        usleep(1_300_000);
        $this->assertSame('starting', $this->store()->read()['phase'], 'Waiting for a stop request must not interrupt consumer sleeps.');
        $this->assertSame(0, $this->command(['server:stop'])->run());
        $this->assertInstallationLock(true);
        $this->assertSame('stopped', file_get_contents($this->directory.'/database-stopped'));
    }

    public function testWorkerWaitsForATransientStatusProbe(): void
    {
        $store = $this->store();
        $store->initialize();
        $store->write(['phase' => 'starting', 'pid' => 0, 'token' => 'probe']);

        $probe = $store->lock('session');
        $file = $this->directory.'/open-session.php';
        (new Filesystem())->dumpFile($file, '<?php
            require $argv[1];
            echo "opening";
            $session = Contao\\E2eTesting\\Inspection\\InspectionSession::open(
                new Contao\\E2eTesting\\Inspection\\InspectionSessionStore($argv[2]), "probe"
            );
            echo "opened";
        ');
        $process = new Process([PHP_BINARY, $file, \dirname(__DIR__, 2).'/vendor/autoload.php', $store->directory]);
        $process->start();

        try {
            $this->assertTrue($process->waitUntil(static fn (): bool => str_contains($process->getOutput(), 'opening')));
            usleep(200_000);
            $probe?->release();
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
            $this->assertStringContainsString('opened', $process->getOutput());
        } finally {
            $probe?->release();
            $process->stop();
        }
    }

    public function testStatusWriteFailureDoesNotPreventDatabaseCleanup(): void
    {
        if (!\function_exists('posix_kill') || !\function_exists('pcntl_signal')) {
            $this->markTestSkipped('This shutdown failure test requires POSIX and PCNTL.');
        }

        $this->start();
        $state = $this->waitForPhase('running');
        $path = $this->store()->directory.'/state.json';
        (new Filesystem())->remove($path);
        (new Filesystem())->mkdir($path);
        posix_kill((int) $state['pid'], SIGTERM);
        $this->waitForExit();
        $this->assertInstallationLock(true);
        $this->assertSame('stopped', file_get_contents($this->directory.'/database-stopped'));
        $this->assertStringContainsString('Could not write inspection shutdown status', file_get_contents($this->store()->logFile()));
    }

    public function testStaleStateDoesNotSignalAPid(): void
    {
        $store = $this->store();
        $store->initialize();
        $store->write(['phase' => 'running', 'pid' => getmypid(), 'token' => 'stale']);

        $status = $this->command(['server:status']);
        $this->assertSame(1, $status->run());
        $this->assertStringContainsString('stale', $status->getOutput());
        $this->assertSame(0, $this->command(['server:stop'])->run());
        $this->assertFalse($store->stopRequested('stale'));
    }

    /**
     * @param array<string, string> $environment
     */
    private function start(string|null $file = null, array $environment = []): void
    {
        $process = $this->command(['server:start', $file ?? __DIR__.'/Fixtures/inspection.php', '-d'], $environment);
        $this->assertSame(0, $process->run(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('Inspection worker started', $process->getOutput());
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     */
    private function command(array $arguments, array $environment = []): Process
    {
        return new Process(
            [PHP_BINARY, $this->directory.'/console.php', ...$arguments],
            $this->directory,
            array_replace([
                'CONTAO_INSPECTION_TEST_DIRECTORY' => $this->directory,
                'CONTAO_E2E_DIRECTORY' => '.contao-e2e',
            ], $environment),
            timeout: 40,
        );
    }

    private function store(): InspectionSessionStore
    {
        return new InspectionSessionStore($this->directory.'/.contao-e2e/runtime/inspection');
    }

    /**
     * @return array<string, int|string>
     */
    private function waitForPhase(string $phase): array
    {
        $this->waitFor(fn (): bool => ($this->store()->read()['phase'] ?? null) === $phase);

        return $this->store()->read();
    }

    private function waitForExit(): void
    {
        $this->waitFor(fn (): bool => !$this->store()->isActive());
    }

    private function waitFor(\Closure $condition): void
    {
        $deadline = microtime(true) + 10;

        do {
            if ($condition()) {
                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);
        $this->fail('Inspection worker did not reach the expected state. '.json_encode($this->store()->read()).' '.(is_file($this->store()->logFile()) ? file_get_contents($this->store()->logFile()) : ''));
    }

    private function assertServerStopped(string $url): void
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:'.parse_url($url, PHP_URL_PORT), $code, $message, 0.1);

        if (false !== $socket) {
            fclose($socket);
        }

        $this->assertFalse($socket, 'The local inspection server must stop with the session.');
    }

    private function assertInstallationLock(bool $available): void
    {
        $lock = fopen($this->directory.'/installation.lock', 'c+');

        try {
            $this->assertSame($available, flock($lock, LOCK_EX | LOCK_NB));
        } finally {
            fclose($lock);
        }
    }
}
