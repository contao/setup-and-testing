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
use Symfony\Component\Filesystem\Path;

final readonly class MappingFileEnumerator
{
    /**
     * @return array<string, string>
     */
    public function files(FileMapping $mapping): array
    {
        if (is_link($mapping->source)) {
            throw new InvalidRecipeException('Mapped source files must not be symbolic links.');
        }

        if (!is_dir($mapping->source)) {
            if (!is_file($mapping->source)) {
                throw new InvalidRecipeException('Mapped sources must be existing regular files or directories.');
            }

            return [$mapping->target => $mapping->source];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($mapping->source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new InvalidRecipeException('Mapped source directories must not contain symbolic links.');
            }

            if (!$entry->isFile() && !$entry->isDir()) {
                throw new InvalidRecipeException('Mapped source directories may only contain regular files and directories.');
            }

            $relative = Path::makeRelative($entry->getPathname(), $mapping->source);
            $files[Path::join($mapping->target, $relative)] = $entry->getPathname();
        }

        ksort($files);

        return $files;
    }
}
