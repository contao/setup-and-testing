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

use Contao\InstallationRecipe\Cache\InMemoryCache;

/**
 * @phpstan-import-type Cookie from SessionSnapshot
 */
final readonly class SessionCache
{
    public function __construct(
        private InMemoryCache $cache,
        private string $scope,
        private SessionStorageInterface $storage,
    ) {
    }

    public function key(string $identifier, string $userAgent): string
    {
        return 'browser-session:'.hash('sha256', serialize([$this->scope, $identifier, $userAgent]));
    }

    public function get(string $key): SessionSnapshot|null
    {
        $snapshot = $this->cache->get($this->storageKey($key));

        return $snapshot instanceof SessionSnapshot ? $snapshot : null;
    }

    public function forget(string $key): void
    {
        $this->cache->set($this->storageKey($key), null);
    }

    /**
     * @param list<Cookie> $cookies
     */
    public function save(string $key, array $cookies): void
    {
        $this->cache->set($this->storageKey($key), $this->storage->capture($cookies));
    }

    /**
     * @return list<Cookie>
     */
    public function restore(SessionSnapshot $snapshot): array
    {
        return $this->storage->restore($snapshot);
    }

    private function storageKey(string $key): string
    {
        return 'browser-session:'.hash('sha256', serialize([$this->scope, $key]));
    }
}
