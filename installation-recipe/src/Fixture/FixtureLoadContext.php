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

use Doctrine\DBAL\Connection;

final readonly class FixtureLoadContext
{
    public function __construct(
        public Connection $connection,
        public FixtureRegistry $registry,
        public TableIdentityResolver $identityResolver,
    ) {
    }
}
