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
use Contao\E2eTesting\Docker\DockerServiceInterface;

final readonly class DockerDatabaseService implements DockerServiceInterface
{
    public function __construct(
        public CacheConfig $cache,
        public DockerDatabaseConfig $config,
        private DockerDatabaseServer $server = new DockerDatabaseServer(),
    ) {
    }

    public function fingerprint(): string
    {
        return hash('sha256', implode("\0", [
            $this->cache->projectDirectory,
            $this->cache->rootDirectory,
            'database',
            $this->config->fingerprint(),
        ]));
    }

    public function warmUp(): void
    {
        $this->server->warmUp($this->cache, $this->config);
    }
}
