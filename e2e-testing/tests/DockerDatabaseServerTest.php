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

use Contao\E2eTesting\Cache\CacheConfig;
use Contao\E2eTesting\Cache\WorkspaceInitializer;
use Contao\E2eTesting\Database\DatabaseReadinessProbe;
use Contao\E2eTesting\Database\DockerClient;
use Contao\E2eTesting\Database\DockerDatabaseConfig;
use Contao\E2eTesting\Database\DockerDatabaseExclusiveLock;
use Contao\E2eTesting\Database\DockerDatabaseLease;
use Contao\E2eTesting\Database\DockerDatabaseLeaseRegistry;
use Contao\E2eTesting\Database\DockerDatabaseServer;
use Contao\E2eTesting\Exception\E2eTestException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class DockerDatabaseServerTest extends TestCase
{
    public function testWarmUpStartsTheContainerWithoutWaitingForReadiness(): void
    {
        $project = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests/docker-warm-up-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir($project);

        $command = $project.'/docker.php';
        $log = $project.'/docker.log';
        $filesystem->dumpFile($command, $this->dockerCommand($log));
        $cache = CacheConfig::forProject($project);
        $registry = new DockerDatabaseLeaseRegistry();
        $server = new DockerDatabaseServer(
            new DockerClient([PHP_BINARY, $command]),
            new DatabaseReadinessProbe(0),
            leaseRegistry: $registry,
        );

        try {
            $server->warmUp($cache, DockerDatabaseConfig::mariaDb());

            $this->assertStringContainsString('"run"', (string) file_get_contents($log));
        } finally {
            $registry->release($this->leasePath($cache));
            $filesystem->remove($project);
        }
    }

    public function testRunningContainerReuseNeedsOnlyOneInspection(): void
    {
        $project = sys_get_temp_dir().'/docker-reuse-'.bin2hex(random_bytes(6));
        $cache = CacheConfig::forProject($project);
        $filesystem = new Filesystem();
        $filesystem->mkdir($project);

        $command = $project.'/docker.php';
        $log = $project.'/docker.log';
        $filesystem->dumpFile($command, $this->dockerCommand($log));
        $server = new DockerDatabaseServer(new DockerClient([PHP_BINARY, $command]));

        try {
            $server->warmUp($cache, DockerDatabaseConfig::mariaDb());
            $filesystem->dumpFile($log, '');
            $server->warmUp($cache, DockerDatabaseConfig::mariaDb());
            $lines = file($log, FILE_IGNORE_NEW_LINES);
            $this->assertNotFalse($lines);
            $commands = array_map(json_decode(...), $lines);

            $this->assertSame([['inspect', '--format', '{{json .}}', 'contao-e2e-'.substr(hash('sha256', $cache->projectDirectory), 0, 12)]], $commands);
        } finally {
            (new DockerDatabaseLeaseRegistry())->release($this->leasePath($cache));
            $filesystem->remove($project);
        }
    }

    public function testStoppedContainerIsStartedAndItsNewStateIsInspected(): void
    {
        $project = sys_get_temp_dir().'/docker-restart-'.bin2hex(random_bytes(6));
        $cache = CacheConfig::forProject($project);
        $filesystem = new Filesystem();
        $filesystem->mkdir($project);

        $command = $project.'/docker.php';
        $log = $project.'/docker.log';
        $filesystem->dumpFile($command, $this->dockerCommand($log));
        $docker = new DockerClient([PHP_BINARY, $command]);
        $server = new DockerDatabaseServer($docker);

        try {
            $server->warmUp($cache, DockerDatabaseConfig::mariaDb());
            $docker->stop('contao-e2e-'.substr(hash('sha256', $cache->projectDirectory), 0, 12));
            $filesystem->dumpFile($log, '');
            $server->warmUp($cache, DockerDatabaseConfig::mariaDb());
            $lines = file($log, FILE_IGNORE_NEW_LINES);
            $this->assertNotFalse($lines);
            $commands = array_map(static fn ($line) => json_decode($line, true)[0], $lines);

            $this->assertSame(['inspect', 'start', 'inspect'], $commands);
        } finally {
            (new DockerDatabaseLeaseRegistry())->release($this->leasePath($cache));
            $filesystem->remove($project);
        }
    }

    public function testMissingStorageMarkerStillRecreatesAnExistingContainer(): void
    {
        $project = sys_get_temp_dir().'/docker-stale-'.bin2hex(random_bytes(6));
        $cache = CacheConfig::forProject($project);
        $filesystem = new Filesystem();
        $filesystem->mkdir($project);

        $command = $project.'/docker.php';
        $log = $project.'/docker.log';
        $filesystem->dumpFile($command, $this->dockerCommand($log));
        $server = new DockerDatabaseServer(new DockerClient([PHP_BINARY, $command]));
        $database = DockerDatabaseConfig::mariaDb();

        try {
            $server->warmUp($cache, $database);
            $filesystem->remove($database->storageMarker($cache));
            $filesystem->dumpFile($log, '');
            $server->warmUp($cache, $database);
            $lines = file($log, FILE_IGNORE_NEW_LINES);
            $this->assertNotFalse($lines);
            $commands = array_map(static fn ($line) => json_decode($line, true)[0], $lines);

            $this->assertContains('rm', $commands);
            $this->assertContains('run', $commands);
        } finally {
            (new DockerDatabaseLeaseRegistry())->release($this->leasePath($cache));
            $filesystem->remove($project);
        }
    }

    public function testReadinessFailureReleasesOwnershipImmediately(): void
    {
        $project = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests/docker-failure-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir($project);

        $command = $project.'/docker.php';
        $filesystem->dumpFile($command, $this->dockerCommand($project.'/docker.log'));
        $cache = CacheConfig::forProject($project);
        $server = new DockerDatabaseServer(new DockerClient([PHP_BINARY, $command]), new DatabaseReadinessProbe(0));

        try {
            (new WorkspaceInitializer())->initialize($cache);

            try {
                $server->provide($cache, DockerDatabaseConfig::mariaDb());
                $this->fail('An unavailable database should fail readiness.');
            } catch (E2eTestException) {
                $lock = DockerDatabaseExclusiveLock::acquire($this->leasePath($cache));
                $this->assertNotNull($lock);
                $lock->release();
            }
        } finally {
            (new DockerDatabaseLeaseRegistry())->release($this->leasePath($cache));
            $filesystem->remove($project);
        }
    }

    public function testContainerStartupFailureReleasesOwnershipImmediately(): void
    {
        $project = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests/docker-start-failure-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir($project);

        $cache = CacheConfig::forProject($project);
        $server = new DockerDatabaseServer(new DockerClient([PHP_BINARY, '-r', 'exit(1);']));

        try {
            try {
                $server->warmUp($cache, DockerDatabaseConfig::mariaDb());
                $this->fail('Container creation should fail.');
            } catch (E2eTestException) {
                $lock = DockerDatabaseExclusiveLock::acquire($this->leasePath($cache));
                $this->assertNotNull($lock);
                $lock->release();
            }
        } finally {
            (new DockerDatabaseLeaseRegistry())->release($this->leasePath($cache));
            $filesystem->remove($project);
        }
    }

    public function testReadinessFailurePreservesExistingOwnership(): void
    {
        $project = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests/docker-existing-'.bin2hex(random_bytes(6));
        $cache = CacheConfig::forProject($project);
        $filesystem = new Filesystem();
        (new WorkspaceInitializer())->initialize($cache);
        $command = $project.'/docker.php';
        $filesystem->dumpFile($command, $this->dockerCommand($project.'/docker.log'));
        $server = new DockerDatabaseServer(new DockerClient([PHP_BINARY, $command]), new DatabaseReadinessProbe(0));

        try {
            $server->warmUp($cache, DockerDatabaseConfig::mariaDb());

            try {
                $server->provide($cache, DockerDatabaseConfig::mariaDb());
                $this->fail('Readiness should fail.');
            } catch (E2eTestException) {
                $this->assertNull(DockerDatabaseExclusiveLock::acquire($this->leasePath($cache)));
            }
        } finally {
            (new DockerDatabaseLeaseRegistry())->release($this->leasePath($cache));
            $filesystem->remove($project);
        }
    }

    public function testStartupFailurePreservesExistingOwnership(): void
    {
        $project = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests/docker-existing-failure-'.bin2hex(random_bytes(6));
        $cache = CacheConfig::forProject($project);
        (new WorkspaceInitializer())->initialize($cache);
        $registry = new DockerDatabaseLeaseRegistry();
        $path = $this->leasePath($cache);
        $registry->acquire($path, static fn () => DockerDatabaseLease::acquire($path, static function (): void {}));
        $server = new DockerDatabaseServer(new DockerClient([PHP_BINARY, '-r', 'exit(1);']));

        try {
            try {
                $server->warmUp($cache, DockerDatabaseConfig::mariaDb());
                $this->fail('Container creation should fail.');
            } catch (E2eTestException) {
                $this->assertNull(DockerDatabaseExclusiveLock::acquire($path));
            }
        } finally {
            $registry->release($path);
            (new Filesystem())->remove($project);
        }
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function cleanupFailures(): iterable
    {
        yield 'container startup' => [false, 'Docker did not expose a MariaDB port'];
        yield 'database readiness' => [true, 'did not become ready'];
    }

    #[DataProvider('cleanupFailures')]
    public function testCleanupFailureDoesNotHideOriginalFailure(bool $readiness, string $message): void
    {
        $project = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests/docker-cleanup-failure-'.bin2hex(random_bytes(6));
        $cache = CacheConfig::forProject($project);
        (new WorkspaceInitializer())->initialize($cache);
        $filesystem = new Filesystem();
        $command = $project.'/docker.php';
        $filesystem->dumpFile($command, '<?php $port = '.var_export($readiness ? '1' : 'invalid', true).';'.<<<'PHP'
            if ('stop' === ($argv[1] ?? null)) { fwrite(STDERR, 'stop failed'); exit(1); }
            if ('run' === ($argv[1] ?? null)) { touch(__FILE__.'.created'); }
            if ('inspect' === ($argv[1] ?? null)) {
                if (!is_file(__FILE__.'.created')) { exit(1); }
                echo json_encode(['State' => ['Running' => true], 'NetworkSettings' => ['Ports' => ['3306/tcp' => [['HostPort' => $port]]]]]);
            }
            PHP,
        );
        $errorLog = $project.'/errors.log';
        $previousLog = ini_set('error_log', $errorLog);
        $server = new DockerDatabaseServer(new DockerClient([PHP_BINARY, $command]), new DatabaseReadinessProbe(0));

        try {
            try {
                $server->provide($cache, DockerDatabaseConfig::mariaDb());
                $this->fail('Readiness should fail.');
            } catch (E2eTestException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());

                if ($readiness) {
                    $this->assertNotNull($exception->getPrevious());
                }
                $this->assertStringContainsString('stop failed', (string) file_get_contents($errorLog));
                $lock = DockerDatabaseExclusiveLock::acquire($this->leasePath($cache));
                $this->assertNotNull($lock);
                $lock->release();
            }
        } finally {
            ini_set('error_log', $previousLog);
            (new DockerDatabaseLeaseRegistry())->release($this->leasePath($cache));
            $filesystem->remove($project);
        }
    }

    private function dockerCommand(string $log): string
    {
        return '<?php $log = '.var_export($log, true).';'.<<<'PHP_WRAP'
            file_put_contents($log, json_encode(array_slice($argv, 1)).PHP_EOL, FILE_APPEND);
            $stateFile = $log.'.state';
            $state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : null;
            $command = $argv[1] ?? null;
            if ('inspect' === $command) {
                if (!$state) { exit(1); }
                echo json_encode($state);
                exit;
            }
            if ('run' === $command) {
                $labels = [];
                foreach ($argv as $i => $argument) {
                    if ('--label' === $argument) {
                        [$key, $value] = explode('=', $argv[$i + 1], 2);
                        $labels[$key] = $value;
                    }
                }
                $state = ['State' => ['Running' => true], 'Config' => ['Labels' => $labels], 'NetworkSettings' => ['Ports' => ['3306/tcp' => [['HostPort' => '1']]]]];
            } elseif ('start' === $command || 'stop' === $command) {
                $state['State']['Running'] = 'start' === $command;
            } elseif ('rm' === $command) {
                unlink($stateFile);
                exit;
            }
            file_put_contents($stateFile, json_encode($state));
            PHP_WRAP;
    }

    private function leasePath(CacheConfig $cache): string
    {
        $container = 'contao-e2e-'.substr(hash('sha256', $cache->projectDirectory), 0, 12);

        return Path::join($cache->rootDirectory, 'locks', $container.'.lock');
    }
}
