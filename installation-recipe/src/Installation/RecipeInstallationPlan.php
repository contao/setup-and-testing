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

use Contao\InstallationRecipe\Recipe\PortableInstallationRecipe;

final readonly class RecipeInstallationPlan
{
    /**
     * @param array<string, mixed> $changes
     */
    public function __construct(
        public PortableInstallationRecipe $recipe,
        public string $targetDirectory,
        public array $changes,
    ) {
    }
}
