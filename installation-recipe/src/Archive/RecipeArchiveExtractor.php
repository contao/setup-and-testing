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

use Contao\InstallationRecipe\Exception\InvalidRecipeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final readonly class RecipeArchiveExtractor
{
    public function __construct(
        private ArchiveLimits $limits = new ArchiveLimits(),
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function extract(string $archivePath): string
    {
        if (!is_file($archivePath)) {
            throw new InvalidRecipeException(\sprintf('The recipe archive "%s" does not exist.', $archivePath));
        }

        $archive = new \ZipArchive();

        if (true !== $archive->open($archivePath, \ZipArchive::RDONLY)) {
            throw new InvalidRecipeException(\sprintf('The recipe archive "%s" cannot be opened.', $archivePath));
        }

        $directory = Path::join(sys_get_temp_dir(), 'contao-installation-recipe', bin2hex(random_bytes(12)));
        $this->filesystem->mkdir($directory);

        try {
            $this->extractEntries($archive, $directory);
        } catch (\Throwable $exception) {
            $this->filesystem->remove($directory);
            $archive->close();

            throw $exception;
        }

        $archive->close();

        return $directory;
    }

    private function extractEntries(\ZipArchive $archive, string $directory): void
    {
        if ($archive->numFiles > $this->limits->entries) {
            throw new InvalidRecipeException('The recipe archive contains too many entries.');
        }

        $totalBytes = 0;

        for ($index = 0; $index < $archive->numFiles; ++$index) {
            $stat = $archive->statIndex($index, \ZipArchive::FL_UNCHANGED);

            if (false === $stat || !isset($stat['name'], $stat['size'])) {
                throw new InvalidRecipeException('The recipe archive contains an unreadable entry.');
            }

            $entry = new ArchiveEntry($index, (string) $stat['name'], (int) $stat['size']);
            $totalBytes += $entry->size;
            $this->assertEntry($archive, $entry, $totalBytes);
            $this->extractEntry($archive, $entry, $directory);
        }
    }

    private function assertEntry(\ZipArchive $archive, ArchiveEntry $entry, int $totalBytes): void
    {
        $name = $entry->name;

        if ('' === $name || str_contains($name, "\0") || str_contains($name, '\\')) {
            throw new InvalidRecipeException(\sprintf('The recipe archive entry "%s" has an unsafe path.', $name));
        }

        $normalized = Path::canonicalize($name);

        if (
            $normalized !== rtrim($name, '/')
            || str_starts_with($name, '/')
            || 1 === preg_match('#^[A-Za-z]:#', $name)
            || 1 === preg_match('#(^|/)\.\.?(?:/|$)#', $name)
        ) {
            throw new InvalidRecipeException(\sprintf('The recipe archive entry "%s" has an unsafe path.', $name));
        }

        if ($entry->size > $this->limits->entryBytes || $totalBytes > $this->limits->totalBytes) {
            throw new InvalidRecipeException('The recipe archive exceeds the configured extraction size limit.');
        }

        if ($this->isSymbolicLink($archive, $entry->index)) {
            throw new InvalidRecipeException(\sprintf('The recipe archive entry "%s" is a symbolic link.', $name));
        }
    }

    private function extractEntry(\ZipArchive $archive, ArchiveEntry $entry, string $directory): void
    {
        $name = $entry->name;
        $destination = Path::join($directory, rtrim($name, '/'));

        if (str_ends_with($name, '/')) {
            $this->filesystem->mkdir($destination);

            return;
        }

        $source = $archive->getStreamIndex($entry->index);

        if (false === $source) {
            throw new InvalidRecipeException(\sprintf('The recipe archive entry "%s" cannot be read.', $name));
        }

        $this->filesystem->mkdir(\dirname($destination));
        $target = fopen($destination, 'w');

        if (false === $target || false === stream_copy_to_stream($source, $target)) {
            \is_resource($target) && fclose($target);
            fclose($source);

            throw new InvalidRecipeException(\sprintf('The recipe archive entry "%s" cannot be extracted.', $name));
        }

        fclose($target);
        fclose($source);
    }

    private function isSymbolicLink(\ZipArchive $archive, int $index): bool
    {
        $operatingSystem = 0;
        $attributes = 0;

        if (!$archive->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
            return false;
        }

        return 0120000 === (($attributes >> 16) & 0170000);
    }
}
