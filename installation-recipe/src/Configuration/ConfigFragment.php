<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\Configuration;

use Contao\InstallationRecipe\Exception\InvalidRecipeException;

final readonly class ConfigFragment
{
    public function __construct(public string $path)
    {
        if (!is_file($path)) {
            throw new InvalidRecipeException(\sprintf('The configuration fragment "%s" does not exist.', $path));
        }
    }
}
