<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Tests;

use Contao\E2eTestBundle\Database\DockerDatabaseConfig;
use Contao\E2eTestBundle\ManagedEdition\ManagedEditionConfig;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;
use PHPUnit\Framework\TestCase;

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

        $this->assertSame($database, $config->environment->database);
    }
}
