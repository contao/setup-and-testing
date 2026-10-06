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

use Contao\E2eTesting\Application\ApplicationConfigInterface;
use Contao\E2eTesting\Cache\CacheConfig;
use Contao\E2eTesting\Database\DatabaseResetMode;
use Contao\E2eTesting\Database\DatabaseServerConfig;
use Contao\E2eTesting\Database\DockerDatabaseConfig;
use Contao\E2eTesting\Database\DockerDatabaseService;
use Contao\E2eTesting\Docker\DockerServiceInterface;
use Contao\InstallationRecipe\File\FileMapping;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;

final readonly class ManagedEditionConfig implements ApplicationConfigInterface
{
    private function __construct(
        public InstallationRecipe $recipe,
        public ManagedEditionEnvironment $environment,
        public DatabaseResetMode $resetMode = DatabaseResetMode::TRUNCATE,
        public string $appEnvironment = 'prod',
    ) {
    }

    public static function create(InstallationRecipe $recipe, string $projectDirectory): self
    {
        return new self($recipe, new ManagedEditionEnvironment(
            CacheConfig::forProject($projectDirectory),
            DatabaseServerConfig::tryFromEnvironment(),
        ));
    }

    public function createApplication(): ManagedEdition
    {
        return (new ManagedEditionFactory())->create($this)->startServer();
    }

    public function withEnvironment(ManagedEditionEnvironment $environment): self
    {
        return new self($this->recipe, $environment, $this->resetMode, $this->appEnvironment);
    }

    public function withDatabase(DatabaseServerConfig|DockerDatabaseConfig $database): self
    {
        return $this->withEnvironment($this->environment->withDatabase($database));
    }

    /**
     * @return list<DockerServiceInterface>
     */
    public function dockerServices(): array
    {
        if ($this->environment->database instanceof DatabaseServerConfig) {
            return [];
        }

        return [new DockerDatabaseService(
            $this->environment->cache,
            $this->environment->database ?? DockerDatabaseConfig::fromEnvironment(),
        )];
    }

    public function withDcaFile(string $path): self
    {
        if (!is_file($path) || !str_ends_with($path, '.php')) {
            throw new \InvalidArgumentException(\sprintf('The DCA file "%s" must be an existing PHP file.', $path));
        }

        $mapping = new FileMapping($path, 'contao/dca/'.basename($path));

        return new self($this->recipe->withFileMapping($mapping), $this->environment, $this->resetMode, $this->appEnvironment);
    }

    public function withSimulatedOrigins(): self
    {
        $path = \dirname(__DIR__, 2).'/config/simulated-origin.yaml';

        foreach ($this->recipe->assets->configFragments as $fragment) {
            if ($fragment->path === $path) {
                return $this;
            }
        }

        return new self($this->recipe->withConfigFile($path), $this->environment, $this->resetMode, $this->appEnvironment);
    }

    public function withResetMode(DatabaseResetMode $resetMode): self
    {
        return new self($this->recipe, $this->environment, $resetMode, $this->appEnvironment);
    }

    public function withAppEnvironment(string $appEnvironment): self
    {
        return new self($this->recipe, $this->environment, $this->resetMode, $appEnvironment);
    }
}
