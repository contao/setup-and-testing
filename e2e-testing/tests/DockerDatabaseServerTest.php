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
use Contao\E2eTesting\Database\DatabaseReadinessProbe;
use Contao\E2eTesting\Database\DockerClient;
use Contao\E2eTesting\Database\DockerDatabaseConfig;
use Contao\E2eTesting\Database\DockerDatabaseLeaseRegistry;
use Contao\E2eTesting\Database\DockerDatabaseServer;
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
