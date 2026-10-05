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

final readonly class InstallationFilePolicy
{
    private string $directory;

    public function __construct(
        private PortableFilePolicy $policy,
        string $directory,
        private InstallationPathValidator $paths = new InstallationPathValidator(),
    ) {
        $this->directory = $this->paths->root($directory);
    }

    public function validateTarget(string $target, bool $directory): void
    {
        $this->policy->validateTarget($target, $directory);
        $this->assertResolvedProtectedTarget($target, $directory);
    }

    public function validateDocumentTarget(string $requested, string $resolved): void
    {
        $this->policy->validateDocumentTarget($requested, $resolved);
        $this->assertResolvedProtectedTarget($resolved, false, $requested);
    }

    private function assertResolvedProtectedTarget(string $target, bool $directory, string|null $document = null): void
    {
        $destination = strtolower(Path::join($this->directory, $target));

        foreach ($this->policy->protectedTargets as $protected) {
            // Dedicated document writers may update their own protected output.
            if (null !== $document && Path::isBasePath(strtolower($protected), strtolower($document))) {
                continue;
            }

            $path = $this->protectedPath($protected);

            if (null === $path) {
                continue;
            }

            $path = strtolower($path);

            if (Path::isBasePath($path, $destination) || !$directory && Path::isBasePath($destination, $path)) {
                throw new InvalidRecipeException(\sprintf('The portable recipe target "%s" resolves to a protected destination.', $target));
            }
        }
    }

    private function protectedPath(string $target): string|null
    {
        // Composer and other subprocesses may have retargeted a protected symbolic link.
        clearstatcache(true);
        $path = realpath(Path::join($this->directory, $target));

        if (false === $path) {
            try {
                $path = $this->paths->validate($this->directory, $target);
            } catch (InvalidRecipeException) {
                // Unresolvable protected paths cannot be used as mapped destinations either.
                return null;
            }
        }

        $path = Path::canonicalize($path);

        return Path::isBasePath($this->directory, $path) ? $path : null;
    }
}
