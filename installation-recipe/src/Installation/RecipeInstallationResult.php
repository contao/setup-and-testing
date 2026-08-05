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

use Contao\InstallationRecipe\Composer\ComposerMergeResult;
use Contao\InstallationRecipe\Fixture\FixtureResult;

final readonly class RecipeInstallationResult
{
    public function __construct(
        public ComposerMergeResult $composer,
        public FixtureResult $fixtures,
        public bool $configurationChanged,
    ) {
    }
}
