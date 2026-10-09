<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Browser\Session;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * @phpstan-import-type Cookie from SessionSnapshot
 */
final readonly class PhpSessionStorage implements SessionStorageInterface
{
    public function __construct(private string $sessionDirectory)
    {
        if ('' === trim($sessionDirectory)) {
            throw new \InvalidArgumentException('The PHP session directory must not be empty.');
        }
    }

    /**
     * @param list<Cookie> $cookies
     */
    public function capture(array $cookies): SessionSnapshot
    {
        return new SessionSnapshot($cookies, $this->readSessionFiles($cookies));
    }

    /**
     * @return list<Cookie>
     */
    public function restore(SessionSnapshot $snapshot): array
    {
        $cookies = $snapshot->cookies;

        $ids = [];
        $filesystem = new Filesystem();

        // Give each browser context its own copy of the clean session snapshot.
        $paths = $this->validatedPaths($snapshot);

        foreach ($paths as $path => $contents) {
            $oldId = substr(basename($path), 5);
            $newId = $ids[$oldId] ??= bin2hex(random_bytes(24));
            $target = Path::join($this->restoreDirectory($path), 'sess_'.$newId);
            $filesystem->mkdir(\dirname($target), 0700);
            $filesystem->dumpFile($target, $contents);
            $filesystem->chmod($target, 0600);
        }

        foreach ($cookies as &$cookie) {
            $cookie['value'] = $ids[$cookie['value']] ?? $cookie['value'];
        }

        return $cookies;
    }

    /**
     * @param list<Cookie> $cookies
     *
     * @return array<string, string>
     */
    private function readSessionFiles(array $cookies): array
    {
        if (!is_dir($this->sessionDirectory)) {
            return [];
        }

        $names = array_map(static fn (array $cookie): string => 'sess_'.$cookie['value'], $cookies);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->sessionDirectory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink() || !\in_array($file->getFilename(), $names, true)) {
                continue;
            }

            $files[Path::makeRelative($file->getPathname(), $this->sessionDirectory)] = $this->readSessionFile($file->getPathname());
        }

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private function validatedPaths(SessionSnapshot $snapshot): array
    {
        $files = [];

        foreach ($snapshot->files as $path => $contents) {
            $relative = Path::canonicalize($path);

            if (str_contains($path, "\0") || Path::isAbsolute($relative) || preg_match('/^[a-zA-Z]:/', $relative) || str_starts_with($relative, '../') || !preg_match('/^sess_[a-zA-Z0-9,-]+$/D', basename($relative))) {
                throw new \InvalidArgumentException('Snapshot files must be PHP session files within the session directory.');
            }

            $files[$relative] = $contents;
        }

        return $files;
    }

    private function restoreDirectory(string $path): string
    {
        $directory = $this->sessionDirectory;

        foreach (explode('/', \dirname($path)) as $part) {
            if ('.' === $part) {
                continue;
            }

            $directory = Path::join($directory, $part);

            if (is_link($directory)) {
                throw new \InvalidArgumentException('Snapshot files must not be restored through symbolic links.');
            }
        }

        return $directory;
    }

    private function readSessionFile(string $path): string
    {
        $file = fopen($path, 'r');

        if (false === $file) {
            throw new \RuntimeException('Could not open the PHP session file.');
        }

        try {
            if (!flock($file, LOCK_SH)) {
                throw new \RuntimeException('Could not lock the PHP session file.');
            }

            $contents = stream_get_contents($file);

            if (false === $contents) {
                throw new \RuntimeException('Could not read the PHP session file.');
            }

            return $contents;
        } finally {
            fclose($file);
        }
    }
}
