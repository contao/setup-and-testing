<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Database;

use Contao\E2eTestBundle\Cache\CacheConfig;
use Contao\E2eTestBundle\Exception\DockerUnavailableException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final readonly class DockerDatabaseServer
{
    public function __construct(
        private DockerClient $docker = new DockerClient(),
        private DatabaseReadinessProbe $readinessProbe = new DatabaseReadinessProbe(),
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function provide(CacheConfig $cache, DockerDatabaseConfig $database): DatabaseServerConfig
    {
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

        $this->readinessProbe->wait($config);

        return $config;
    }

    public function stop(CacheConfig $cache): void
    {
        foreach ($this->docker->find($this->containerPrefix($cache)) as $container) {
            $this->docker->stop($container);
        }
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

    private function isStorageInitialized(CacheConfig $cache, DockerDatabaseConfig $database): bool
    {
        $marker = $database->storageMarker($cache);

        return is_file($marker) && $database->fingerprint() === trim((string) file_get_contents($marker));
    }
}
