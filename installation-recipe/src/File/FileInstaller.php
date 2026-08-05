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
use Symfony\Component\Filesystem\Path;

final readonly class FileInstaller
{
    public function __construct(private Filesystem $filesystem = new Filesystem())
    {
    }

    /**
     * @param list<FileMapping> $mappings
     */
    public function install(array $mappings, string $targetDirectory): void
    {
        $this->validate($mappings, $targetDirectory);

        foreach ($mappings as $mapping) {
            $destination = Path::canonicalize(Path::join($targetDirectory, $mapping->target));

            if (is_dir($mapping->source)) {
                $this->filesystem->mirror($mapping->source, $destination, null, ['override' => $mapping->overwrite]);
            } else {
                $this->filesystem->copy($mapping->source, $destination, $mapping->overwrite);
            }
        }
    }

    /**
     * @param list<FileMapping> $mappings
     */
    public function validate(array $mappings, string $targetDirectory): void
    {
        foreach ($mappings as $mapping) {
            $destination = Path::canonicalize(Path::join($targetDirectory, $mapping->target));

            if (!Path::isBasePath($targetDirectory, $destination)) {
                throw new InvalidRecipeException(\sprintf('The mapped target "%s" leaves the installation directory.', $mapping->target));
            }

            if ($this->filesystem->exists($destination) && !$mapping->overwrite) {
                throw new InvalidRecipeException(\sprintf('The mapped target "%s" already exists.', $mapping->target));
            }
        }
    }
}
