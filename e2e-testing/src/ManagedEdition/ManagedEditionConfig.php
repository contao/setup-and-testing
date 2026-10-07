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
use Contao\E2eTesting\Application\ApplicationRuntime;
use Contao\E2eTesting\Cache\CacheConfig;
use Contao\E2eTesting\Cache\CachedSourceFingerprint;
use Contao\E2eTesting\Cache\FingerprintCalculator;
use Contao\E2eTesting\Cache\SourceFingerprint;
use Contao\E2eTesting\Database\DatabaseResetMode;
use Contao\E2eTesting\Database\DatabaseServerConfig;
use Contao\E2eTesting\Database\DockerDatabaseConfig;
use Contao\E2eTesting\Database\DockerDatabaseService;
use Contao\E2eTesting\Docker\DockerServiceInterface;
use Contao\E2eTesting\Http\PhpServerConfig;
use Contao\E2eTesting\Installation\InstallationPool;
use Contao\InstallationRecipe\File\FileMapping;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;

final class ManagedEditionConfig implements ApplicationConfigInterface
{
    private PhpServerConfig $phpServer;

    private function __construct(
        public readonly InstallationRecipe $recipe,
        public readonly ManagedEditionEnvironment $environment,
        public readonly DatabaseResetMode $resetMode = DatabaseResetMode::TRUNCATE,
        public readonly string $appEnvironment = 'prod',
    ) {
        $this->phpServer = (new PhpServerConfig())->withOpcache()->withIniSettings([
            'opcache.memory_consumption' => 128,
            'opcache.max_accelerated_files' => 20000,
            'opcache.interned_strings_buffer' => 32,
            'realpath_cache_size' => '4096K',
            'realpath_cache_ttl' => 600,
        ]);
    }

    public static function create(InstallationRecipe $recipe, string $projectDirectory): self
    {
        return new self($recipe, new ManagedEditionEnvironment(
            CacheConfig::forProject($projectDirectory),
            DatabaseServerConfig::tryFromEnvironment(),
        ));
    }

    public function createApplication(ApplicationRuntime $runtime): ManagedEdition
    {
        $factory = new ManagedEditionFactory(
            new ManagedEditionRuntime(),
            new FingerprintCalculator(new CachedSourceFingerprint(new SourceFingerprint(), $runtime->cache)),
            new InstallationPool(),
            $runtime,
        );

        return $factory->create($this)->startServer();
    }

    public function phpServer(): PhpServerConfig
    {
        return $this->phpServer;
    }

    public function withPhpServer(PhpServerConfig $phpServer): self
    {
        $clone = clone $this;
        $clone->phpServer = $phpServer;

        return $clone;
    }

    public function withEnvironment(ManagedEditionEnvironment $environment): self
    {
        return (new self($this->recipe, $environment, $this->resetMode, $this->appEnvironment))->withPhpServer($this->phpServer);
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

        return (new self($this->recipe->withFileMapping($mapping), $this->environment, $this->resetMode, $this->appEnvironment))->withPhpServer($this->phpServer);
    }

    public function withSimulatedOrigins(): self
    {
        $path = \dirname(__DIR__, 2).'/config/simulated-origin.yaml';

        foreach ($this->recipe->assets->configFragments as $fragment) {
            if ($fragment->path === $path) {
                return $this;
            }
        }

        return (new self($this->recipe->withConfigFile($path), $this->environment, $this->resetMode, $this->appEnvironment))->withPhpServer($this->phpServer);
    }

    public function withResetMode(DatabaseResetMode $resetMode): self
    {
        return (new self($this->recipe, $this->environment, $resetMode, $this->appEnvironment))->withPhpServer($this->phpServer);
    }

    public function withAppEnvironment(string $appEnvironment): self
    {
        return (new self($this->recipe, $this->environment, $this->resetMode, $appEnvironment))->withPhpServer($this->phpServer);
    }
}
