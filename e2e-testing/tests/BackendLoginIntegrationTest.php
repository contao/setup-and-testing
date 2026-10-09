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

use Contao\E2eTesting\Application\ApplicationInterface;
use Contao\E2eTesting\Application\ApplicationRuntime;
use Contao\E2eTesting\Application\LocalApplicationConfig;
use Contao\E2eTesting\Browser\BackendBrowser;
use Contao\E2eTesting\Browser\BackendLoginSessionCache;
use Contao\E2eTesting\Browser\BrowserType;
use Contao\E2eTesting\Browser\Session\CookieSessionStorage;
use Contao\E2eTesting\Browser\Session\PhpSessionStorage;
use Contao\E2eTesting\Browser\Session\SessionCache;
use Contao\E2eTesting\Http\PhpServerConfig;
use Contao\InstallationRecipe\Cache\InMemoryCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BackendLoginIntegrationTest extends TestCase
{
    private const SESSION_COOKIE_NAME = 'contao_test_session';

    private string $directory;

    private ApplicationRuntime $runtime;

    private ApplicationInterface $application;

    private InMemoryCache $cache;

    protected function setUp(): void
    {
        if ('1' !== getenv('CONTAO_E2E_BROWSER_TESTS')) {
            $this->markTestSkipped('Enable CONTAO_E2E_BROWSER_TESTS to run browser integration tests.');
        }

        $this->directory = sys_get_temp_dir().'/backend login '.bin2hex(random_bytes(6));
        (new Filesystem())->mirror(__DIR__.'/Fixtures/backend-login', $this->directory);
        $this->runtime = ApplicationRuntime::create();
        $this->cache = new InMemoryCache();
        $this->application = $this->runtime->createApplication($this->applicationConfig());
    }

    protected function tearDown(): void
    {
        if (isset($this->application)) {
            $this->application->release();
        }

        if (isset($this->runtime)) {
            $this->runtime->close();
        }

        if (isset($this->directory)) {
            (new Filesystem())->remove($this->directory);
        }
    }

    /**
     * @return iterable<string, array{BrowserType}>
     */
    public static function browsers(): iterable
    {
        yield 'Firefox' => [BrowserType::Firefox];
        yield 'Chromium' => [BrowserType::Chromium];
    }

    #[DataProvider('browsers')]
    public function testReusesACleanSessionAfterAResetAndServerRestart(BrowserType $type): void
    {
        $first = $this->backend($type);
        $this->assertSame($first, $first->loginOrReuseSessionAs());
        $this->assertSame('k.jones', $first->page()->locator('h1')->textContent());
        $first->visit('/mutate');
        $this->application->browserRuntime()->reset();
        (new Filesystem())->remove($this->directory.'/var/sessions');
        $this->application->release();
        $this->application = $this->runtime->createApplication($this->applicationConfig());

        $second = $this->backend($type)->loginOrReuseSessionAs();
        $this->assertSame('k.jones', $second->page()->locator('h1')->textContent());
        $this->assertSame('clean', $second->page()->locator('#state')->textContent());
        $this->assertSame('1', file_get_contents($this->directory.'/logins'));
        $firstId = $this->sessionId($second);
        $third = $this->backend($type)->loginOrReuseSessionAs('k.jones');
        $this->assertNotSame($firstId, $this->sessionId($third));
        $second->visit('/mutate');
        $third->visit('/contao');
        $this->assertSame('clean', $third->page()->locator('#state')->textContent());
    }

    public function testInvalidRemoteSessionFallsBackToLoginAndSwitchesUsers(): void
    {
        $cache = new BackendLoginSessionCache($this->cache, new SessionCache($this->cache, 'remote', new CookieSessionStorage()));
        $first = new BackendBrowser($this->application->createBrowser(), $cache);
        $first->loginOrReuseSessionAs('k.jones');
        $first->visit('/contao/logout');

        $second = new BackendBrowser($this->application->createBrowser(), $cache);
        $second->loginOrReuseSessionAs('k.jones');
        $this->assertSame('2', file_get_contents($this->directory.'/logins'));
        $second->loginOrReuseSessionAs('other', 'custom');
        $this->assertSame('other', $second->page()->locator('h1')->textContent());
        $second->loginOrReuseSessionAs('k.jones');
        $this->assertSame('k.jones', $second->page()->locator('h1')->textContent());
        $this->assertSame('3', file_get_contents($this->directory.'/logins'));
    }

    public function testChangingBackendUsersPreservesCookiesForOtherSitesAndPaths(): void
    {
        $backend = $this->backend();
        $hostname = parse_url($this->application->uri(), PHP_URL_HOST);
        $this->assertIsString($hostname);
        $backend->browser()->context()->addCookies([
            ['name' => 'external', 'value' => 'external-state', 'url' => 'https://external.test'],
            ['name' => 'frontend', 'value' => 'frontend-state', 'domain' => $hostname, 'path' => '/frontend'],
        ]);
        $backend->loginOrReuseSessionAs('k.jones');
        $backend->loginOrReuseSessionAs('other', 'custom');
        $backend->loginOrReuseSessionAs('k.jones');

        $cookies = array_column($backend->browser()->context()->cookies(), 'value', 'name');
        $this->assertSame('external-state', $cookies['external'] ?? null);
        $this->assertSame('frontend-state', $cookies['frontend'] ?? null);
        $this->assertSame('k.jones', $backend->page()->locator('h1')->textContent());
    }

    public function testFailedLoginDoesNotPopulateTheCache(): void
    {
        $backend = $this->backend();

        try {
            $backend->loginOrReuseSessionAs('k.jones', 'wrong');
            $this->fail('An unsuccessful login must throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Could not log into', $exception->getMessage());
        }

        $backend->loginOrReuseSessionAs('k.jones');
        $this->assertSame('k.jones', $backend->page()->locator('h1')->textContent());
        $this->assertSame('1', file_get_contents($this->directory.'/logins'));
    }

    private function applicationConfig(): LocalApplicationConfig
    {
        return LocalApplicationConfig::php($this->directory)->withPhpServer(
            (new PhpServerConfig())->withIniSettings(['session.name' => self::SESSION_COOKIE_NAME]),
        );
    }

    private function backend(BrowserType $type = BrowserType::Firefox): BackendBrowser
    {
        return new BackendBrowser($this->application->createBrowser($type), new BackendLoginSessionCache(
            $this->cache,
            new SessionCache($this->cache, $this->directory, new PhpSessionStorage($this->directory.'/var/sessions')),
        ));
    }

    private function sessionId(BackendBrowser $backend): string
    {
        foreach ($backend->browser()->context()->cookies() as $cookie) {
            if (self::SESSION_COOKIE_NAME === $cookie['name']) {
                return $cookie['value'];
            }
        }

        throw new \LogicException('No session cookie was set.');
    }
}
