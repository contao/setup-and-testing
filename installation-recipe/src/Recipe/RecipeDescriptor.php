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

final readonly class RecipeDescriptor
{
    public function __construct(
        public string $name,
        public int $format,
    ) {
    }
}
