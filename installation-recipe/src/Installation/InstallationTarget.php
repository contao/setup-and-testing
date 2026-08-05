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

use Doctrine\DBAL\Connection;

final readonly class InstallationTarget
{
    public function __construct(
        public string $directory,
        public Connection $connection,
        public InstallationRuntimeInterface $runtime,
    ) {
    }
}
