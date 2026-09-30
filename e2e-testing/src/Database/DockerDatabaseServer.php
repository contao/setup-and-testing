<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Database;

use Contao\E2eTesting\Cache\CacheConfig;
use Contao\E2eTesting\Cache\WorkspaceInitializer;
use Contao\E2eTesting\Exception\DockerUnavailableException;
use Contao\E2eTesting\Exception\E2eTestException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final readonly class DockerDatabaseServer
{
    public function __construct(
        private DockerClient $docker = new DockerClient(),
        private DatabaseReadinessProbe $readinessProbe = new DatabaseReadinessProbe(),
        private Filesystem $filesystem = new Filesystem(),
        private DockerDatabaseLeaseRegistry $leaseRegistry = new DockerDatabaseLeaseRegistry(),
        private WorkspaceInitializer $workspaceInitializer = new WorkspaceInitializer(),
    ) {
    }

    public function provide(CacheConfig $cache, DockerDatabaseConfig $database): DatabaseServerConfig
    {
        $config = $this->startContainer($cache, $database);
        $this->readinessProbe->wait($config);

        return $config;
    }

    public function warmUp(CacheConfig $cache, DockerDatabaseConfig ...$databases): void
    {
        $this->workspaceInitializer->initialize($cache);

        foreach ($databases as $database) {
            $this->startContainer($cache, $database);
        }
    }

    public function stop(CacheConfig $cache, bool $force = false): void
    {
        $containers = $this->docker->find($this->containerPrefix($cache));
        $locks = $force ? [] : $this->acquireStopLocks($cache, $containers);

        try {
            foreach ($containers as $container) {
                $this->docker->stop($container);
            }
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
    }

    private function startContainer(CacheConfig $cache, DockerDatabaseConfig $database): DatabaseServerConfig
    {
        $container = $this->containerName($cache, $database);
        $leasePath = $this->leasePath($cache, $container);
        $this->leaseRegistry->acquire(
            $leasePath,
            fn () => DockerDatabaseLease::acquire($leasePath, fn () => $this->docker->stop($container)),
        );

        $lock = fopen(Path::join($cache->rootDirectory, 'locks/database-server.lock'), 'c+');

        if (false === $lock) {
            throw new DockerUnavailableException('Could not lock the Docker database server setup.');
        }

        flock($lock, LOCK_EX);

        try {
            $config = $this->start($cache, $database);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $config;
    }

    private function start(CacheConfig $cache, DockerDatabaseConfig $database): DatabaseServerConfig
    {
        $container = $this->containerName($cache, $database);
        $databaseDirectory = $database->storageDirectory($cache);
        $storageInitialized = $this->isStorageInitialized($cache, $database);
        $this->filesystem->mkdir($databaseDirectory);

        if ($this->docker->exists($container) && (!$storageInitialized || !$this->docker->usesDatabaseConfiguration($container, $database, $databaseDirectory))) {
            $this->docker->remove($container);
        }

        $this->filesystem->dumpFile($database->storageMarker($cache), $database->fingerprint()."\n");

        if (!$this->docker->isRunning($container)) {
            if ($this->docker->exists($container)) {
                $this->docker->start($container);
            } else {
                $this->docker->create($container, $database, $databaseDirectory);
            }
        }

        return new DatabaseServerConfig(\sprintf('mysql://root:contao-e2e@127.0.0.1:%d', $this->docker->port($container)));
    }

    private function containerName(CacheConfig $cache, DockerDatabaseConfig $database): string
    {
        $suffix = $database->isDefault() ? '' : '-'.$database->storageKey();

        return $this->containerPrefix($cache).$suffix;
    }

    private function containerPrefix(CacheConfig $cache): string
    {
        return 'contao-e2e-'.substr(hash('sha256', $cache->projectDirectory), 0, 12);
    }

    private function leasePath(CacheConfig $cache, string $container): string
    {
        return Path::join($cache->rootDirectory, 'locks', $container.'.lock');
    }

    /**
     * @param list<string> $containers
     *
     * @return list<DockerDatabaseExclusiveLock>
     */
    private function acquireStopLocks(CacheConfig $cache, array $containers): array
    {
        $locks = [];

        foreach ($containers as $container) {
            $lock = DockerDatabaseExclusiveLock::acquire($this->leasePath($cache, $container));

            if (!$lock) {
                throw new E2eTestException('A Docker database is still in use. Wait for the E2E tests to finish or use --force.');
            }

            $locks[] = $lock;
        }

        return $locks;
    }

    private function isStorageInitialized(CacheConfig $cache, DockerDatabaseConfig $database): bool
    {
        $marker = $database->storageMarker($cache);

        return is_file($marker) && $database->fingerprint() === trim((string) file_get_contents($marker));
    }
}
