<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\Archive;

final readonly class ArchiveEntry
{
    public function __construct(
        public int $index,
        public string $name,
        public int $size,
    ) {
    }
}
