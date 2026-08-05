<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Database;

use Contao\E2eTestBundle\Cache\CacheConfig;
use Contao\E2eTestBundle\Exception\E2eTestException;
use Symfony\Component\Filesystem\Path;

final readonly class DockerDatabaseConfig
{
    private function __construct(
        public string $image,
        public string $rootPasswordEnvironment,
    ) {
    }

    public static function mariaDb(string $image = 'mariadb:11.4'): self
    {
        return new self($image, 'MARIADB_ROOT_PASSWORD');
    }

    public static function mysql(string $image = 'mysql:8.4'): self
    {
        return new self($image, 'MYSQL_ROOT_PASSWORD');
    }

    public static function fromEnvironment(): self
    {
        $type = getenv('CONTAO_E2E_DATABASE_TYPE') ?: 'mariadb';
        $image = getenv('CONTAO_E2E_DATABASE_IMAGE') ?: null;

        return match ($type) {
            'mariadb' => self::mariaDb($image ?? 'mariadb:11.4'),
            'mysql' => self::mysql($image ?? 'mysql:8.4'),
            default => throw new E2eTestException('CONTAO_E2E_DATABASE_TYPE must be either "mariadb" or "mysql".'),
        };
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->rootPasswordEnvironment."\0".$this->image);
    }

    public function storageKey(): string
    {
        return substr($this->fingerprint(), 0, 12);
    }

    public function isDefault(): bool
    {
        return self::mariaDb()->fingerprint() === $this->fingerprint();
    }

    public function storageDirectory(CacheConfig $cache): string
    {
        if ($this->isDefault()) {
            return Path::join($cache->rootDirectory, 'database/data');
        }

        return Path::join($cache->rootDirectory, 'database', $this->storageKey(), 'data');
    }

    public function storageMarker(CacheConfig $cache): string
    {
        return Path::join($this->storageDirectory($cache), '.contao-e2e-database');
    }
}
