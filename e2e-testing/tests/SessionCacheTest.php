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

use Contao\E2eTesting\Browser\Session\CookieSessionStorage;
use Contao\E2eTesting\Browser\Session\PhpSessionStorage;
use Contao\E2eTesting\Browser\Session\SessionCache;
use Contao\InstallationRecipe\Cache\InMemoryCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SessionCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/php sessions '.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sessionCookieNames(): iterable
    {
        yield 'default cookie name' => ['PHPSESSID'];
        yield 'custom cookie name' => ['application_session'];
    }

    #[DataProvider('sessionCookieNames')]
    public function testRestoresOnlyTheMatchingSessionWithAnIndependentId(string $cookieName): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->directory.'/prod/sess_initial', 'clean session');
        $filesystem->dumpFile($this->directory.'/prod/sess_unrelated', 'unrelated state');

        $cache = new SessionCache(new InMemoryCache(), 'application', new PhpSessionStorage($this->directory));
        $cookies = [['name' => $cookieName, 'value' => 'initial', 'domain' => 'localhost', 'path' => '/', 'expires' => -1, 'httpOnly' => true, 'secure' => false, 'sameSite' => 'Lax']];
        $cache->save('state', $cookies);
        $session = $cache->get('state');
        $this->assertNotNull($session);
        $this->assertSame(['prod/sess_initial' => 'clean session'], $session->files);
        $filesystem->remove($this->directory);
        $restored = $cache->restore($session);
        $this->assertSame($cookieName, $restored[0]['name']);
        $this->assertNotSame('initial', $restored[0]['value']);
        $this->assertSame('clean session', file_get_contents($this->directory.'/prod/sess_'.$restored[0]['value']));
        $filesystem->dumpFile($this->directory.'/prod/sess_'.$restored[0]['value'], 'mutated session');
        $other = $cache->restore($session);
        $this->assertNotSame($restored[0]['value'], $other[0]['value']);
        $this->assertSame('clean session', file_get_contents($this->directory.'/prod/sess_'.$other[0]['value']));
    }

    public function testCacheKeysSeparateIdentifiersBrowsersAndApplications(): void
    {
        $memory = new InMemoryCache();
        $cache = new SessionCache($memory, 'application', new CookieSessionStorage());
        $key = $cache->key('state', 'Firefox');
        $this->assertNotSame($key, $cache->key('other-state', 'Firefox'));
        $this->assertNotSame($key, $cache->key('state', 'Chrome'));
        $other = new SessionCache($memory, 'other-application', new CookieSessionStorage());
        $this->assertNotSame($key, $other->key('state', 'Firefox'));
    }

    public function testScopesAlsoIsolatePlainCacheKeys(): void
    {
        $memory = new InMemoryCache();
        $first = new SessionCache($memory, 'first-application', new CookieSessionStorage());
        $second = new SessionCache($memory, 'second-application', new CookieSessionStorage());
        $first->save('state', []);
        $this->assertNull($second->get('state'));
        $second->forget('state');
        $this->assertNotNull($first->get('state'));
    }

    public function testCookieStoragePreservesCookiesWithoutSessionFiles(): void
    {
        $storage = new CookieSessionStorage();
        $cookies = [['name' => 'application_session', 'value' => 'remote-id', 'domain' => 'example.test', 'path' => '/', 'expires' => -1, 'httpOnly' => true, 'secure' => true, 'sameSite' => 'Lax']];
        $snapshot = $storage->capture($cookies);
        $this->assertSame([], $snapshot->files);
        $this->assertSame($cookies, $storage->restore($snapshot));
    }

    public function testCookieOnlySessionsCanBeInvalidated(): void
    {
        $memory = new InMemoryCache();
        $cache = new SessionCache($memory, 'remote-application', new CookieSessionStorage());
        $cache->save('state', []);
        $this->assertNotNull($cache->get('state'));
        $cache->forget('state');
        $this->assertNull($cache->get('state'));
        $cache->save('state', []);
        $memory->clear();
        $this->assertNull($cache->get('state'));
    }
}
