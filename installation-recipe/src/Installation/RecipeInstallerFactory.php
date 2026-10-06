<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\Installation;

use Contao\InstallationRecipe\Cache\InMemoryCache;
use Contao\InstallationRecipe\File\FileInstaller;
use Contao\InstallationRecipe\File\InstallationPathValidator;
use Contao\InstallationRecipe\File\PortableFilePolicy;
use Contao\InstallationRecipe\Fixture\FixtureLoader;
use Contao\InstallationRecipe\Fixture\FixtureParser;
use Contao\InstallationRecipe\Fixture\FixtureValueResolver;

final class RecipeInstallerFactory
{
    public function create(PortableFilePolicy|null $policy = null): RecipeInstaller
    {
        $cache = new InMemoryCache();
        $parser = new FixtureParser($cache);
        $fixtures = new FixtureLoader($parser, new FixtureValueResolver(), $cache);

        return new RecipeInstaller(
            new InstallationDocumentInstallers(),
            new InstallationContentInstallers($fixtures, new FileInstaller()),
            new RecipeInstallationPlanner($policy ?? new PortableFilePolicy(), new InstallationPathValidator(), $parser),
        );
    }
}
