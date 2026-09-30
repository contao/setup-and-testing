<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Tests;

use Contao\E2eTesting\Cache\CacheConfig;
use Contao\E2eTesting\Database\DockerDatabaseConfig;
use Contao\E2eTesting\Database\DockerDatabaseService;
use Contao\E2eTesting\Installation\ApplicationPreparer;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ManagedEditionConfigTest extends TestCase
{
    public function testLeavesTheDatabaseUnconfiguredForTheDockerFallback(): void
    {
        $databaseUrl = getenv('CONTAO_E2E_DATABASE_URL');
        putenv('CONTAO_E2E_DATABASE_URL');
        $recipe = InstallationRecipe::create(ComposerConfig::managedEdition('^5.7'));
        $config = ManagedEditionConfig::create($recipe, \dirname(__DIR__, 2));

        if (false !== $databaseUrl) {
            putenv('CONTAO_E2E_DATABASE_URL='.$databaseUrl);
        }

        $this->assertNull($config->environment->database);
    }

    public function testSelectsAnExplicitDockerDatabase(): void
    {
        $recipe = InstallationRecipe::create(ComposerConfig::managedEdition('^5.7'));
        $database = DockerDatabaseConfig::mysql('mysql:8.0');
        $config = ManagedEditionConfig::create($recipe, \dirname(__DIR__, 2))->withDatabase($database);
        $service = $config->dockerServices()[0];

        $this->assertSame($database, $config->environment->database);
        $this->assertInstanceOf(DockerDatabaseService::class, $service);
        $this->assertSame($database, $service->config);
    }

    public function testDockerServicesForDifferentProjectsHaveDifferentFingerprints(): void
    {
        $database = DockerDatabaseConfig::mariaDb();
        $temporaryDirectory = sys_get_temp_dir();
        $sharedCache = $temporaryDirectory.'/shared-e2e-cache';
        $firstCache = CacheConfig::forProject($temporaryDirectory.'/project-a')->withRootDirectory($sharedCache);
        $secondCache = CacheConfig::forProject($temporaryDirectory.'/project-b')->withRootDirectory($sharedCache);
        $first = new DockerDatabaseService($firstCache, $database);
        $second = new DockerDatabaseService($secondCache, $database);

        $this->assertNotSame($first->fingerprint(), $second->fingerprint());
    }

    public function testSelectsTheAppEnvironmentWithoutChangingTheOriginalConfig(): void
    {
        $recipe = InstallationRecipe::create(ComposerConfig::managedEdition('^5.7'));
        $prod = ManagedEditionConfig::create($recipe, \dirname(__DIR__, 2));
        $dev = $prod->withAppEnvironment('dev');

        $this->assertSame('prod', $prod->appEnvironment);
        $this->assertSame('dev', $dev->appEnvironment);
        $this->assertSame('dev', $dev->withDatabase(DockerDatabaseConfig::mysql('mysql:8.0'))->appEnvironment);
    }

    public function testAddsAProjectDcaFileToTheRecipe(): void
    {
        $directory = sys_get_temp_dir().'/contao-e2e-dca-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory);

        $path = $directory.'/tl_content.php';
        $filesystem->dumpFile($path, '<?php $GLOBALS["TL_DCA"]["tl_content"]["fields"]["example"]["eval"]["mandatory"] = true;');

        try {
            $recipe = InstallationRecipe::create(ComposerConfig::managedEdition('^5.7'));
            $config = ManagedEditionConfig::create($recipe, \dirname(__DIR__, 2))->withDcaFile($path);
            $mapping = $config->recipe->assets->fileMappings[0];

            $this->assertSame($path, $mapping->source);
            $this->assertSame('contao/dca/tl_content.php', $mapping->target);
            $this->assertSame([], $recipe->assets->fileMappings);

            (new ApplicationPreparer())->prepare($config, $directory.'/project', null);
            $this->assertSame(file_get_contents($path), file_get_contents($directory.'/project/contao/dca/tl_content.php'));
        } finally {
            $filesystem->remove($directory);
        }
    }
}
