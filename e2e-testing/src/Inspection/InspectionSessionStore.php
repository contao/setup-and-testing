<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Inspection;

use Contao\E2eTesting\Cache\CacheConfig;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final readonly class InspectionSessionStore
{
    public function __construct(public string $directory)
    {
    }

    public static function forCache(CacheConfig $cache): self
    {
        return new self(Path::join($cache->rootDirectory, 'runtime', 'inspection'));
    }

    public function initialize(): void
    {
        (new Filesystem())->mkdir($this->directory, 0700);
    }

    public function lock(string $name): InspectionLock|null
    {
        return InspectionLock::acquire($this->directory.'/'.$name.'.lock');
    }

    public function isActive(): bool
    {
        return is_file($this->directory.'/session.lock') && !$this->lock('session');
    }

    /**
     * @return array<string, int|string>
     */
    public function read(): array
    {
        $file = $this->directory.'/state.json';

        if (!is_file($file)) {
            return [];
        }

        $state = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        if (!\is_array($state)) {
            throw new \RuntimeException('Invalid inspection session state.');
        }

        foreach ($state as $key => $value) {
            if (!\is_string($key) || (!\is_string($value) && !\is_int($value))) {
                throw new \RuntimeException('Invalid inspection session state.');
            }
        }

        return $state;
    }

    /**
     * @param array<string, int|string> $state
     */
    public function write(array $state): void
    {
        (new Filesystem())->dumpFile($this->directory.'/state.json', json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($this->directory.'/state.json', 0600);
    }

    public function requestStop(string $token): void
    {
        (new Filesystem())->dumpFile($this->directory.'/stop', $token);
    }

    public function stopRequested(string $token): bool
    {
        return is_file($this->directory.'/stop') && hash_equals($token, (string) file_get_contents($this->directory.'/stop'));
    }

    public function logFile(): string
    {
        return $this->directory.'/worker.log';
    }
}
