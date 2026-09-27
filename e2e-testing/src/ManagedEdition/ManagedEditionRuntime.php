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

use Contao\E2eTesting\Cache\WorkspaceInitializer;
use Contao\E2eTesting\Database\DatabaseServerConfig;
use Contao\E2eTesting\Database\DockerDatabaseConfig;
use Contao\E2eTesting\Database\DockerDatabaseServer;

final readonly class ManagedEditionRuntime
{
    public function __construct(
        private WorkspaceInitializer $workspaceInitializer = new WorkspaceInitializer(),
        private DockerDatabaseServer $dockerDatabaseServer = new DockerDatabaseServer(),
    ) {
    }

    public function initialize(ManagedEditionEnvironment $environment): DatabaseServerConfig
    {
        $this->workspaceInitializer->initialize($environment->cache);

        if ($environment->database instanceof DatabaseServerConfig) {
            return $environment->database;
        }

        return $this->dockerDatabaseServer->provide(
            $environment->cache,
            $environment->database ?? DockerDatabaseConfig::fromEnvironment(),
        );
    }
}
