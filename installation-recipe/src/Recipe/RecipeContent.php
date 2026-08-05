<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\Recipe;

use Contao\InstallationRecipe\Fixture\FixtureSet;

final readonly class RecipeContent
{
    public function __construct(
        public FixtureSet $fixtures,
        public RecipeAssets $assets,
    ) {
    }
}
