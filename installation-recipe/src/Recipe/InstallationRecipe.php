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

use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Configuration\ConfigFragment;
use Contao\InstallationRecipe\File\FileMapping;
use Contao\InstallationRecipe\Fixture\FixtureSet;

final readonly class InstallationRecipe
{
    private function __construct(
        public ComposerConfig $composer,
        public FixtureSet $fixtures,
        public RecipeAssets $assets,
    ) {
    }

    public static function create(ComposerConfig $composer): self
    {
        return new self($composer, FixtureSet::empty(), new RecipeAssets());
    }

    public function withComposer(ComposerConfig $composer): self
    {
        return new self($composer, $this->fixtures, $this->assets);
    }

    public function withConfigFile(string $path): self
    {
        return new self($this->composer, $this->fixtures, $this->assets->withConfigFragment(new ConfigFragment($path)));
    }

    public function withFixtureFile(string $path): self
    {
        return new self($this->composer, $this->fixtures->withFile($path), $this->assets);
    }

    public function withFileMapping(FileMapping $mapping): self
    {
        return new self($this->composer, $this->fixtures, $this->assets->withFileMapping($mapping));
    }
}
