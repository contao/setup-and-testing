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

use Contao\E2eTesting\Application\ApplicationRuntime;
use Contao\E2eTesting\Cache\FingerprintCalculator;
use Contao\E2eTesting\Composer\ComposerInstaller;
use Contao\E2eTesting\Database\DatabaseManager;
use Contao\E2eTesting\Database\DatabaseResetter;
use Contao\E2eTesting\Database\DatabaseServerConfig;
use Contao\E2eTesting\Http\ServerManager;
use Contao\E2eTesting\Installation\ApplicationPreparer;
use Contao\E2eTesting\Installation\InstallationBuilder;
use Contao\E2eTesting\Installation\InstallationPool;
use Contao\E2eTesting\Installation\InstallationWorkspace;
use Contao\E2eTesting\Installation\PreparedInstallation;
use Contao\E2eTesting\Process\ContaoConsole;
use Contao\E2eTesting\Process\ProcessRunner;
use Contao\InstallationRecipe\Fixture\FixtureLoader;
use Contao\InstallationRecipe\Fixture\FixtureParser;
use Contao\InstallationRecipe\Fixture\FixtureValueResolver;
use Symfony\Component\Filesystem\Path;

final readonly class ManagedEditionFactory
{
    public function __construct(
        private ManagedEditionRuntime $runtime,
        private FingerprintCalculator $fingerprintCalculator,
        private InstallationPool $installationPool,
        private ApplicationRuntime $applicationRuntime,
    ) {
    }

    public function create(ManagedEditionConfig $config): ManagedEdition
    {
        $cache = $config->environment->cache;
        $databaseServer = $this->runtime->initialize($config->environment);
        $fingerprints = $this->fingerprintCalculator->calculate($config);
        $dependencyFingerprint = $fingerprints->dependency;

        if ('1' === getenv('CONTAO_E2E_NO_CACHE')) {
            $dependencyFingerprint = hash('sha256', $dependencyFingerprint.random_bytes(16));
        }

        $lease = $this->installationPool->acquire($cache, $dependencyFingerprint);
        $databaseName = 'contao_e2e_'.substr($dependencyFingerprint, 0, 16).'_'.$lease->slot;
        $database = $this->createDatabase($databaseServer, $databaseName);
        $installation = new PreparedInstallation($lease, $database, $fingerprints);
        $processRunner = new ProcessRunner();
        $console = new ContaoConsole($processRunner, $config->appEnvironment);
        $builder = new InstallationBuilder(
            new ComposerInstaller($processRunner),
            new ApplicationPreparer(),
            $console,
            $this->applicationRuntime->cache->scope($database->connection()),
        );

        try {
            $builder->prepare($config, new InstallationWorkspace($installation->directory(), $fingerprints), $database);
        } catch (\Throwable $exception) {
            $database->close();
            $lease->release();

            throw $exception;
        }

        return new ManagedEdition(
            new ManagedEditionState($installation, $config, $console),
            new ServerManager(appEnvironment: $config->appEnvironment),
            $this->applicationRuntime->createBrowserRuntime(Path::join($cache->rootDirectory, 'traces')),
            $this->applicationRuntime,
        );
    }

    private function createDatabase(DatabaseServerConfig $server, string $name): DatabaseManager
    {
        $cache = $this->applicationRuntime->cache;
        $fixtures = new FixtureLoader(new FixtureParser($cache), new FixtureValueResolver(), $cache);

        return new DatabaseManager($server, $name, $fixtures, new DatabaseResetter());
    }
}
