<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Database;

use Contao\InstallationRecipe\Fixture\FixtureResult;
use Contao\InstallationRecipe\Fixture\FixtureSet;

interface InstallationDatabaseInterface
{
    public function applicationUrl(): string;

    public function create(): void;

    public function reset(FixtureSet $fixtures): FixtureResult;

    public function hasSchema(): bool;
}
