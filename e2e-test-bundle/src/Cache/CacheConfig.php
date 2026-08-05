<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Cache;

use Symfony\Component\Filesystem\Path;

final readonly class CacheConfig
{
    private function __construct(
        public string $projectDirectory,
        public string $rootDirectory,
    ) {
    }

    public static function forProject(string $projectDirectory): self
    {
        $projectDirectory = Path::canonicalize($projectDirectory);
        $configured = getenv('CONTAO_E2E_DIRECTORY');
        $rootDirectory = false === $configured || '' === $configured
            ? Path::join($projectDirectory, '.contao-e2e')
            : Path::makeAbsolute($configured, $projectDirectory);

        return new self($projectDirectory, Path::canonicalize($rootDirectory));
    }

    public function withRootDirectory(string $rootDirectory): self
    {
        return new self($this->projectDirectory, Path::makeAbsolute($rootDirectory, $this->projectDirectory));
    }
}
