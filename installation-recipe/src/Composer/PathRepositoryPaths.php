<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\Composer;

use Symfony\Component\Filesystem\Path;

final class PathRepositoryPaths
{
    /**
     * @param array<array-key, mixed> $repositories
     *
     * @return array<array-key, mixed>
     */
    public function resolve(array $repositories, string $directory): array
    {
        foreach ($repositories as &$repository) {
            if (!$this->isPathRepository($repository)) {
                continue;
            }

            $repository['url'] = Path::makeAbsolute($this->expandPath($repository['url']), $directory);
        }

        return $repositories;
    }

    /**
     * @param array<array-key, mixed> $repositories
     *
     * @return array<array-key, mixed>
     */
    public function relativize(array $repositories, string $directory): array
    {
        foreach ($repositories as &$repository) {
            if ($this->isPathRepository($repository)) {
                $relative = Path::makeRelative($repository['url'], $directory);
                $repository['url'] = '' === $relative ? '.' : $relative;
            }
        }

        return $repositories;
    }

    private function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/') || str_starts_with($path, '~\\')) {
            $home = $this->environment('HOME');
            if ('' === $home && '\\' === \DIRECTORY_SEPARATOR) {
                $home = $this->environment('USERPROFILE');
            }

            return ('' === $home ? Path::getHomeDirectory() : $home).substr($path, 1);
        }

        if (!preg_match('#^(?:\$(\w++)|%(\w++)%)(.*)$#', $path, $matches)) {
            return $path;
        }

        $variable = '' !== $matches[1] ? $matches[1] : $matches[2];
        $value = $this->environment($variable);
        if ('HOME' === $variable && '' === $value && '\\' === \DIRECTORY_SEPARATOR) {
            $value = $this->environment('USERPROFILE');
        }

        return $value.$matches[3];
    }

    private function environment(string $name): string
    {
        return (string) ($_SERVER[$name] ?? $_ENV[$name] ?? getenv($name));
    }

    /**
     * @phpstan-assert-if-true array{type: 'path', url: string, options?: mixed} $repository
     */
    private function isPathRepository(mixed $repository): bool
    {
        return \is_array($repository) && 'path' === ($repository['type'] ?? null) && \is_string($repository['url'] ?? null);
    }
}
