<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Tests\Fixture;

use Contao\E2eTesting\Database\DockerDatabaseConfig;
use Contao\E2eTesting\ManagedEdition\AbstractManagedEditionTestCase as BaseManagedEditionTestCase;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;

abstract class AbstractManagedEditionTestCase extends BaseManagedEditionTestCase
{
    protected static function createApplicationConfig(): ManagedEditionConfig
    {
        return ManagedEditionConfig::create(
            InstallationRecipe::create(ComposerConfig::managedEdition('^5.7')),
            \dirname(__DIR__, 2),
        )->withDatabase(DockerDatabaseConfig::mysql());
    }
}
