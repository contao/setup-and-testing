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

final readonly class PathPackage
{
    public function __construct(
        public string $package,
        public string $path,
        public string $version,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toComposerRepository(string $targetDirectory): array
    {
        $relativePath = Path::makeRelative($this->path, $targetDirectory);

        return [
            'type' => 'path',
            'url' => '' === $relativePath ? '.' : $relativePath,
            'options' => [
                'symlink' => true,
                'versions' => [$this->package => $this->version],
            ],
        ];
    }
}
