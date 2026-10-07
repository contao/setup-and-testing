<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

use Contao\E2eTesting\Application\ApplicationRuntime;
use Contao\E2eTesting\Cache\FingerprintSet;
use Contao\E2eTesting\Database\DatabaseManager;
use Contao\E2eTesting\Database\DatabaseResetter;
use Contao\E2eTesting\Database\DatabaseServerConfig;
use Contao\E2eTesting\Database\DockerDatabaseLease;
use Contao\E2eTesting\Database\DockerDatabaseLeaseRegistry;
use Contao\E2eTesting\Http\ServerManager;
use Contao\E2eTesting\Installation\InstallationLease;
use Contao\E2eTesting\Installation\PreparedInstallation;
use Contao\E2eTesting\ManagedEdition\ManagedEdition;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\E2eTesting\ManagedEdition\ManagedEditionState;
use Contao\E2eTesting\Process\ContaoConsole;
use Contao\E2eTesting\Process\ProcessRunner;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Fixture\FixtureLoader;
use Contao\InstallationRecipe\Fixture\FixtureParser;
use Contao\InstallationRecipe\Fixture\FixtureValueResolver;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;
use Symfony\Component\Filesystem\Filesystem;

return static function (ApplicationRuntime $runtime): ManagedEdition {
    $directory = (string) getenv('CONTAO_INSPECTION_TEST_DIRECTORY');
    $filesystem = new Filesystem();
    $filesystem->dumpFile($directory.'/installation/project/public/index.php', '<?php echo "Prepared inspection state";');

    $lock = fopen($directory.'/installation.lock', 'c+');
    flock($lock, LOCK_EX);
    $databaseLease = $directory.'/database.lock';
    (new DockerDatabaseLeaseRegistry())->acquire($databaseLease, static fn () => DockerDatabaseLease::acquire(
        $databaseLease,
        static fn () => file_put_contents($directory.'/database-stopped', 'stopped'),
    ));
    $config = ManagedEditionConfig::create(InstallationRecipe::create(ComposerConfig::managedEdition('^6.0')), $directory);
    $fixtures = new FixtureLoader(new FixtureParser($runtime->cache), new FixtureValueResolver(), $runtime->cache);
    $installation = new PreparedInstallation(
        new InstallationLease($directory.'/installation', 0, $lock),
        new DatabaseManager(new DatabaseServerConfig('mysql://localhost'), 'inspection', $fixtures, new DatabaseResetter()),
        new FingerprintSet('inspection', 'inspection', 'inspection'),
    );

    return new ManagedEdition(
        new ManagedEditionState($installation, $config, new ContaoConsole(new ProcessRunner())),
        new ServerManager(phpServer: $config->phpServer()),
        $runtime->createBrowserRuntime($directory.'/traces'),
        $runtime,
    );
};
