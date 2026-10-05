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

final readonly class InstallationPathValidator
{
    public function root(string $directory): string
    {
        $root = realpath($directory);

        if (false === $root || !is_dir($root)) {
            throw new InvalidRecipeException('The installation directory must exist.');
        }

        return Path::canonicalize($root);
    }

    public function validate(string $directory, string $relativePath): string
    {
        $root = $this->root($directory);

        if (str_contains($relativePath, "\0") || str_contains($relativePath, '\\') || str_contains($relativePath, ':') || Path::isAbsolute($relativePath)) {
            throw new InvalidRecipeException('The installation target must be a safe relative path.');
        }

        $destination = Path::canonicalize(Path::join($root, $relativePath));
        $this->assertContained($root, $destination);
        $destination = $this->resolve($root, $destination);

        if ($root === $destination) {
            throw new InvalidRecipeException('A mapped target must not replace the installation directory.');
        }

        return $destination;
    }

    private function resolve(string $root, string $destination): string
    {
        $pending = explode('/', Path::makeRelative($destination, $root));
        $path = $root;
        $links = 0;

        while ([] !== $pending) {
            $part = array_shift($pending);

            if ('' === $part || '.' === $part) {
                continue;
            }

            if ('..' === $part) {
                $path = $this->parentDirectory($path);
                continue;
            }

            $this->assertComponent($part);
            $path = Path::join($path, $part);

            if (is_link($path)) {
                if (++$links > 40) {
                    throw new InvalidRecipeException('The installation target contains too many symbolic links.');
                }

                [$path, $parts] = $this->linkTarget($path);
                $pending = [...$parts, ...$pending];
                continue;
            }

            if (file_exists($path)) {
                $resolved = realpath($path);

                if (false === $resolved) {
                    throw new InvalidRecipeException('The installation target cannot be resolved.');
                }

                $path = Path::canonicalize($resolved);
            }

            $this->assertType($path, [] !== $pending);
        }

        $this->assertContained($root, $path);

        return $path;
    }

    /**
     * @return array{string, list<string>}
     */
    private function linkTarget(string $path): array
    {
        $target = readlink($path);

        if (false === $target) {
            throw new InvalidRecipeException('The installation symbolic link cannot be read.');
        }

        $target = Path::normalize($target);
        $root = Path::getRoot($target);

        // Keep parent components pending until preceding symbolic links have been resolved.
        return [
            '' === $root ? \dirname($path) : $root,
            explode('/', substr($target, \strlen($root))),
        ];
    }

    private function assertContained(string $root, string $path): void
    {
        if (!Path::isBasePath($root, $path)) {
            throw new InvalidRecipeException('The installation target resolves outside the installation directory.');
        }
    }

    private function parentDirectory(string $path): string
    {
        if (!is_dir($path)) {
            throw new InvalidRecipeException('A symbolic link parent component must follow an existing directory.');
        }

        return Path::getDirectory($path);
    }

    private function assertComponent(string $part): void
    {
        if (str_contains($part, ':') || str_contains($part, "\0") || $part !== rtrim($part, '. ') || preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $part)) {
            throw new InvalidRecipeException('Installation targets must not use ambiguous Windows paths.');
        }
    }

    private function assertType(string $path, bool $parent): void
    {
        if ($parent && file_exists($path) && !is_dir($path)) {
            throw new InvalidRecipeException('Installation target parents must be directories.');
        }

        if (file_exists($path) && !is_file($path) && !is_dir($path)) {
            throw new InvalidRecipeException('Installation targets must be regular files or directories.');
        }
    }
}
