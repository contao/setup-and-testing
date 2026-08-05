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

final readonly class ArchiveLimits
{
    public function __construct(
        public int $entries = 10_000,
        public int $entryBytes = 100_000_000,
        public int $totalBytes = 500_000_000,
    ) {
    }
}
