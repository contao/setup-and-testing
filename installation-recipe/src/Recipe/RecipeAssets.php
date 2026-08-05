<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\Recipe;

use Contao\InstallationRecipe\Configuration\ConfigFragment;
use Contao\InstallationRecipe\File\FileMapping;

final readonly class RecipeAssets
{
    /**
     * @param list<ConfigFragment> $configFragments
     * @param list<FileMapping>    $fileMappings
     */
    public function __construct(
        public array $configFragments = [],
        public array $fileMappings = [],
    ) {
    }

    public function withConfigFragment(ConfigFragment $fragment): self
    {
        return new self([...$this->configFragments, $fragment], $this->fileMappings);
    }

    public function withFileMapping(FileMapping $mapping): self
    {
        return new self($this->configFragments, [...$this->fileMappings, $mapping]);
    }
}
