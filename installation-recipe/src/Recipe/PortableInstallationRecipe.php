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

use Contao\InstallationRecipe\Composer\ComposerDependencies;

final readonly class PortableInstallationRecipe
{
    public function __construct(
        public RecipeDescriptor $descriptor,
        public ComposerDependencies $dependencies,
        public RecipeContent $content,
    ) {
    }
}
