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

use Contao\E2eTestBundle\Cache\FingerprintCalculator;
use Contao\E2eTestBundle\Composer\ComposerInstaller;
use Contao\E2eTestBundle\Database\DatabaseManager;
use Contao\E2eTestBundle\Installation\ApplicationPreparer;
use Contao\E2eTestBundle\Installation\InstallationBuilder;
use Contao\E2eTestBundle\Installation\InstallationPool;
use Contao\E2eTestBundle\Installation\PreparedInstallation;
use Contao\E2eTestBundle\Process\ContaoConsole;
use Contao\E2eTestBundle\Process\ProcessRunner;

final readonly class ManagedEditionFactory
{
    public function __construct(
        private ManagedEditionRuntime $runtime = new ManagedEditionRuntime(),
        private FingerprintCalculator $fingerprintCalculator = new FingerprintCalculator(),
        private InstallationPool $installationPool = new InstallationPool(),
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
        $database = new DatabaseManager($databaseServer, $databaseName);
        $installation = new PreparedInstallation($lease, $database, $fingerprints);
        $processRunner = new ProcessRunner();
        $console = new ContaoConsole($processRunner);
        $builder = new InstallationBuilder(
            new ComposerInstaller($processRunner),
            new ApplicationPreparer(),
            $console,
        );

        try {
            $builder->prepare($config, $installation);
        } catch (\Throwable $exception) {
            $database->close();
            $lease->release();

            throw $exception;
        }

        return new ManagedEdition(new ManagedEditionState($installation, $config, $console));
    }
}
