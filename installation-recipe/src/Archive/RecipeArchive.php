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

use Contao\InstallationRecipe\Manifest\RecipeManifestLoader;
use Contao\InstallationRecipe\Recipe\PortableInstallationRecipe;
use Symfony\Component\Filesystem\Filesystem;

final class RecipeArchive
{
    private bool $closed = false;

    private function __construct(
        public readonly PortableInstallationRecipe $recipe,
        public readonly string $directory,
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    public static function open(string $path, RecipeArchiveExtractor|null $extractor = null, RecipeManifestLoader|null $loader = null): self
    {
        $extractor ??= new RecipeArchiveExtractor();
        $loader ??= new RecipeManifestLoader();
        $directory = $extractor->extract($path);

        try {
            return new self($loader->load($directory), $directory);
        } catch (\Throwable $exception) {
            (new Filesystem())->remove($directory);

            throw $exception;
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        (new Filesystem())->remove($this->directory);
        $this->closed = true;
    }
}
