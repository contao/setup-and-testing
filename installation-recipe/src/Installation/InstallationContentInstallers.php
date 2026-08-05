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

use Contao\InstallationRecipe\File\FileInstaller;
use Contao\InstallationRecipe\Fixture\FixtureLoader;

final readonly class InstallationContentInstallers
{
    public function __construct(
        public FixtureLoader $fixtures = new FixtureLoader(),
        public FileInstaller $files = new FileInstaller(),
    ) {
    }
}
