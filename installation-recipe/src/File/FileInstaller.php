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
    public function __construct(
        private Filesystem $filesystem = new Filesystem(),
        private InstallationPathValidator $paths = new InstallationPathValidator(),
        private MappingFileEnumerator $enumerator = new MappingFileEnumerator(),
        private PortableFilePolicy|null $policy = null,
    ) {
    }

    public function withPolicy(PortableFilePolicy $policy): self
    {
        return new self($this->filesystem, $this->paths, $this->enumerator, $policy);
    }

    /**
     * @param list<FileMapping> $mappings
     */
    public function install(array $mappings, string $targetDirectory): void
    {
        $this->validate($mappings, $targetDirectory);

        foreach ($mappings as $mapping) {
            $entries = [$mapping->target => $mapping->source, ...$this->enumerator->files($mapping)];

            foreach ($entries as $target => $source) {
                $destination = $this->destination(new FileMapping($source, $target, $mapping->overwrite), $targetDirectory);

                if (is_dir($source)) {
                    $this->filesystem->mkdir($destination);
                } else {
                    $this->copyFile($source, $destination, $mapping->overwrite);
                }
            }
        }
    }

    /**
     * @param list<FileMapping> $mappings
     */
    public function validate(array $mappings, string $targetDirectory): void
    {
        foreach ($mappings as $mapping) {
            $this->policy?->validate($mapping);
            $destination = $this->destination($mapping, $targetDirectory);

            $this->validateType($mapping->source, $destination);

            foreach ($this->enumerator->files($mapping) as $target => $source) {
                $this->validateType($source, $this->destination(new FileMapping($source, $target, $mapping->overwrite), $targetDirectory));
            }

            if ($this->filesystem->exists($destination) && !$mapping->overwrite) {
                throw new InvalidRecipeException(\sprintf('The mapped target "%s" already exists.', $mapping->target));
            }
        }
    }

    private function destination(FileMapping $mapping, string $directory): string
    {
        $destination = $this->paths->validate($directory, $mapping->target);
        $relative = Path::makeRelative($destination, $this->paths->root($directory));
        $this->policy?->forInstallation($directory)->validateTarget($relative, is_dir($mapping->source));

        return $destination;
    }

    private function copyFile(string $source, string $destination, bool $overwrite): void
    {
        if (!$overwrite && is_file($destination) && filemtime($source) <= filemtime($destination)) {
            return;
        }

        $this->filesystem->mkdir(\dirname($destination));
        $temporary = $this->filesystem->tempnam(\dirname($destination), '.recipe-');

        try {
            // Replace the directory entry so existing hard links keep their original contents.
            $this->filesystem->copy($source, $temporary, true);
            $this->filesystem->rename($temporary, $destination, true);
        } finally {
            $this->filesystem->remove($temporary);
        }
    }

    private function validateType(string $source, string $destination): void
    {
        if (file_exists($destination) && is_dir($source) !== is_dir($destination)) {
            throw new InvalidRecipeException('Mapped sources and existing destinations must have the same file type.');
        }
    }
}
