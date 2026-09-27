<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\ManagedEdition;

use Contao\E2eTesting\Cache\CacheConfig;
use Contao\E2eTesting\Composer\ComposerRunConfig;
use Contao\E2eTesting\Database\DatabaseServerConfig;
use Contao\E2eTesting\Database\DockerDatabaseConfig;

final readonly class ManagedEditionEnvironment
{
    public function __construct(
        public CacheConfig $cache,
        public DatabaseServerConfig|DockerDatabaseConfig|null $database = null,
        public ComposerRunConfig $composer = new ComposerRunConfig(),
    ) {
    }

    public function withComposer(ComposerRunConfig $composer): self
    {
        return new self($this->cache, $this->database, $composer);
    }

    public function withDatabase(DatabaseServerConfig|DockerDatabaseConfig|null $database): self
    {
        return new self($this->cache, $database, $this->composer);
    }
}
