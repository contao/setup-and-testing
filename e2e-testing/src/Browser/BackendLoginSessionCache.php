<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Browser;

use Contao\E2eTesting\Browser\Session\SessionCache;
use Contao\E2eTesting\Browser\Session\SessionSnapshot;
use Contao\InstallationRecipe\Cache\InMemoryCache;

/**
 * @phpstan-import-type Cookie from SessionSnapshot
 */
final readonly class BackendLoginSessionCache
{
    public function __construct(
        private InMemoryCache $cache,
        private SessionCache $sessions,
    ) {
    }

    public function key(string $username, string $password, string $userAgent): string
    {
        $identifier = 'contao-backend:'.hash('sha256', serialize([$username, $password]));

        return $this->sessions->key($identifier, $userAgent);
    }

    public function get(string $key): BackendLoginSession|null
    {
        $snapshot = $this->sessions->get($key);
        $userLabel = $this->cache->get('backend-user:'.$key);

        if (!$snapshot || !\is_string($userLabel)) {
            return null;
        }

        return new BackendLoginSession($snapshot, $userLabel);
    }

    public function forget(string $key): void
    {
        $this->sessions->forget($key);
        $this->cache->set('backend-user:'.$key, null);
    }

    /**
     * @param list<Cookie> $cookies
     */
    public function save(string $key, array $cookies, string $userLabel): void
    {
        $this->sessions->save($key, $cookies);
        $this->cache->set('backend-user:'.$key, $userLabel);
    }

    /**
     * @return list<Cookie>
     */
    public function restore(BackendLoginSession $session): array
    {
        return $this->sessions->restore($session->snapshot);
    }
}
