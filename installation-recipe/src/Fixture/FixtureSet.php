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

use Contao\InstallationRecipe\Exception\InvalidRecipeException;

final readonly class FixtureSet
{
    /**
     * @param list<string> $files
     */
    public function __construct(public array $files)
    {
        foreach ($files as $file) {
            if (!is_file($file)) {
                throw new InvalidRecipeException(\sprintf('The fixture file "%s" does not exist.', $file));
            }
        }
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function withFile(string $file): self
    {
        return new self([...$this->files, $file]);
    }
}
