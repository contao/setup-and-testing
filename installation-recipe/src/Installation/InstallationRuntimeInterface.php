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

interface InstallationRuntimeInterface
{
    public function installDependencies(string $targetDirectory): void;

    public function migrate(string $targetDirectory): void;
}
