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
            if (str_contains(implode(' ', $argv), 'HostPort')) { echo $port; exit; }
            if (str_contains(implode(' ', $argv), 'State.Running')) { echo 'true'; exit; }
            if ('inspect' === ($argv[1] ?? null)) { exit(1); }
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
        return '<?php file_put_contents('.var_export($log, true).', json_encode(array_slice($argv, 1)).PHP_EOL, FILE_APPEND);'
            .' if (str_contains(implode(" ", $argv), "HostPort")) { echo "1"; exit(0); }'
            .' if (($argv[1] ?? null) === "inspect") { exit(1); }';
    }

    private function leasePath(CacheConfig $cache): string
    {
        $container = 'contao-e2e-'.substr(hash('sha256', $cache->projectDirectory), 0, 12);

        return Path::join($cache->rootDirectory, 'locks', $container.'.lock');
    }
}
