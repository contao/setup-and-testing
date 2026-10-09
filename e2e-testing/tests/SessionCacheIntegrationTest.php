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
use Contao\E2eTesting\Browser\BrowserType;
use Contao\E2eTesting\Browser\Session\PhpSessionStorage;
use Contao\E2eTesting\Browser\Session\SessionCache;
use Contao\E2eTesting\Http\PhpServerConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SessionCacheIntegrationTest extends TestCase
{
    private string $directory;

    private ApplicationRuntime $runtime;

    private ApplicationInterface $application;

    protected function setUp(): void
    {
        if ('1' !== getenv('CONTAO_E2E_BROWSER_TESTS')) {
            $this->markTestSkipped('Enable CONTAO_E2E_BROWSER_TESTS to run browser integration tests.');
        }

        $this->directory = sys_get_temp_dir().'/generic sessions '.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mirror(__DIR__.'/Fixtures/session-state', $this->directory);
        $filesystem->mkdir($this->directory.'/sessions');

        $this->runtime = ApplicationRuntime::create();
        $config = LocalApplicationConfig::php($this->directory)->withPhpServer((new PhpServerConfig())->withIniSettings([
            'session.name' => 'application_session',
            'session.save_path' => $this->directory.'/sessions',
        ]));
        $this->application = $this->runtime->createApplication($config);
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
    public function testRestoresPhpSessionsWithoutContao(BrowserType $type): void
    {
        $sessions = new SessionCache($this->runtime->cache, $this->directory, new PhpSessionStorage($this->directory.'/sessions'));
        $first = $this->application->createBrowser($type);
        $first->visit('/state');
        $this->assertSame('1', $first->page()->locator('body')->textContent());
        $sessions->save('counter', $first->context()->cookies());
        $snapshot = $sessions->get('counter');
        $this->assertNotNull($snapshot);
        $first->visit('/state');
        $this->assertSame('2', $first->page()->locator('body')->textContent());
        $second = $this->application->createBrowser($type);
        $second->context()->addCookies($sessions->restore($snapshot));
        $second->visit('/state');
        $this->assertSame('2', $second->page()->locator('body')->textContent());
        $first->visit('/state');
        $this->assertSame('3', $first->page()->locator('body')->textContent());
        $this->application->browserRuntime()->reset();
        (new Filesystem())->remove($this->directory.'/sessions');
        $third = $this->application->createBrowser($type);
        $third->context()->addCookies($sessions->restore($snapshot));
        $third->visit('/state');
        $this->assertSame('2', $third->page()->locator('body')->textContent());
    }
}
