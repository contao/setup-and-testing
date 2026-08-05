<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\Composer;

final readonly class ComposerMergeResult
{
    /**
     * @param array<string, string|null> $requirements
     * @param array<string, string|null> $developmentRequirements
     */
    public function __construct(
        public array $requirements,
        public array $developmentRequirements,
        public bool $changed,
    ) {
    }
}
