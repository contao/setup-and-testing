<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\ManagedEdition;

use Contao\E2eTestBundle\Cache\CacheConfig;
use Contao\E2eTestBundle\Database\DatabaseResetMode;
use Contao\E2eTestBundle\Database\DatabaseServerConfig;
use Contao\E2eTestBundle\Database\DockerDatabaseConfig;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;

final readonly class ManagedEditionConfig
{
    private function __construct(
        public InstallationRecipe $recipe,
        public ManagedEditionEnvironment $environment,
        public DatabaseResetMode $resetMode = DatabaseResetMode::TRUNCATE,
    ) {
    }

    public static function create(InstallationRecipe $recipe, string $projectDirectory): self
    {
        return new self($recipe, new ManagedEditionEnvironment(
            CacheConfig::forProject($projectDirectory),
            DatabaseServerConfig::tryFromEnvironment(),
        ));
    }

    public function withEnvironment(ManagedEditionEnvironment $environment): self
    {
        return new self($this->recipe, $environment, $this->resetMode);
    }

    public function withDatabase(DatabaseServerConfig|DockerDatabaseConfig $database): self
    {
        return $this->withEnvironment($this->environment->withDatabase($database));
    }

    public function withResetMode(DatabaseResetMode $resetMode): self
    {
        return new self($this->recipe, $this->environment, $resetMode);
    }
}
