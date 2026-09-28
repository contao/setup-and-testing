<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\Fixture;

final readonly class FixtureDefinition
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public FixtureSource $source,
        public string|null $name,
        public array $data,
    ) {
    }
}
