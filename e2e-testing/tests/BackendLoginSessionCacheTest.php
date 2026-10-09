<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Tests;

use Contao\E2eTesting\Browser\BackendLoginSessionCache;
use Contao\E2eTesting\Browser\Session\CookieSessionStorage;
use Contao\E2eTesting\Browser\Session\SessionCache;
use Contao\InstallationRecipe\Cache\InMemoryCache;
use PHPUnit\Framework\TestCase;

final class BackendLoginSessionCacheTest extends TestCase
{
    public function testCacheKeysSeparateCredentials(): void
    {
        $memory = new InMemoryCache();
        $cache = new BackendLoginSessionCache($memory, new SessionCache($memory, 'edition', new CookieSessionStorage()));
        $key = $cache->key('k.jones', 'kevinjones', 'Firefox');
        $this->assertNotSame($key, $cache->key('other', 'kevinjones', 'Firefox'));
        $this->assertNotSame($key, $cache->key('k.jones', 'changed', 'Firefox'));
        $this->assertStringNotContainsString('kevinjones', $key);
    }

    public function testAssociatesTheContaoUserWithTheGenericSnapshot(): void
    {
        $memory = new InMemoryCache();
        $sessions = new SessionCache($memory, 'edition', new CookieSessionStorage());
        $cache = new BackendLoginSessionCache($memory, $sessions);
        $key = $cache->key('k.jones', 'kevinjones', 'Firefox');
        $cache->save($key, [], 'User k.jones');
        $session = $cache->get($key);
        $this->assertNotNull($session);
        $this->assertSame('User k.jones', $session->userLabel);
        $this->assertSame($sessions->get($key), $session->snapshot);
        $cache->forget($key);
        $this->assertNull($cache->get($key));
        $this->assertNull($sessions->get($key));
        $cache->save($key, [], 'User k.jones');
        $memory->clear();
        $this->assertNull($cache->get($key));
    }

    public function testDoesNotReuseGenericSnapshotsWithoutVerifiedContaoUsers(): void
    {
        $memory = new InMemoryCache();
        $sessions = new SessionCache($memory, 'edition', new CookieSessionStorage());
        $cache = new BackendLoginSessionCache($memory, $sessions);
        $sessions->save('state', []);
        $this->assertNull($cache->get('state'));
    }
}
