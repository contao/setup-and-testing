<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\File;

use Contao\InstallationRecipe\Exception\InvalidRecipeException;
use Symfony\Component\Filesystem\Filesystem;

final readonly class FileMapping
{
    public function __construct(
        public string $source,
        public string $target,
        public bool $overwrite = false,
    ) {
        if (!(new Filesystem())->exists($source)) {
            throw new InvalidRecipeException(\sprintf('The mapped source "%s" does not exist.', $source));
        }

        if ('' === $target || str_starts_with($target, '/') || preg_match('#(^|/)\.\.(/|$)#', $target)) {
            throw new InvalidRecipeException(\sprintf('The mapped target "%s" is not a safe relative path.', $target));
        }
    }
}
