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

use Contao\E2eTesting\Application\Application;
use Contao\E2eTesting\Application\ApplicationConfig;
use Contao\E2eTesting\Application\ApplicationRuntime;
use Contao\E2eTesting\Browser\BackendBrowser;
use Contao\E2eTesting\Browser\BackendLoginSessionCache;
use Contao\E2eTesting\Browser\BrowserOptions;
use Contao\E2eTesting\Browser\BrowserRuntime;
use Contao\E2eTesting\Browser\BrowserSession;
use Contao\E2eTesting\Browser\BrowserSessionFactoryInterface;
use Contao\E2eTesting\Browser\BrowserType;
use Contao\E2eTesting\Browser\Session\CookieSessionStorage;
use Contao\E2eTesting\Browser\Session\SessionCache;
use Contao\E2eTesting\Http\WebServerConfig;
use Contao\E2eTesting\Http\WebServerManager;
use PHPUnit\Framework\TestCase;
use Playwright\Browser\BrowserContextInterface;
use Playwright\Page\PageInterface;
use Symfony\Component\Filesystem\Filesystem;

class ApplicationTest extends TestCase
{
    public function testPlaywrightUsesTheApplicationUrlAndSubdirectory(): void
    {
        $filesystem = new Filesystem();
        $directory = sys_get_temp_dir().'/application-url-'.bin2hex(random_bytes(6));
        $filesystem->dumpFile($directory.'/public/index.php', '<?php echo "OK";');
        $server = (new WebServerManager())->start(WebServerConfig::php($directory));
        $uri = $server->baseUri.'/app';
        $session = new BrowserSession($uri, $this->createStub(BrowserContextInterface::class), $this->createStub(PageInterface::class));
        $factory = $this->createMock(BrowserSessionFactoryInterface::class);
        $factory
            ->expects($this->once())
            ->method('create')
            ->with(BrowserType::Firefox, $uri, $this->isInstanceOf(BrowserOptions::class))
            ->willReturn($session)
        ;
        $application = new Application(ApplicationConfig::create($server->baseUri.'/app'), new BrowserRuntime('/unused', $factory), ApplicationRuntime::shared(), $server);

        try {
            $this->assertSame($session, $application->createBrowser());
            $this->assertSame($uri.'/endpoint', $application->uri('/endpoint'));
        } finally {
            $application->release();
            $filesystem->remove($directory);
        }
    }

    public function testExistingContaoBackendUsesTheSameGenericBrowserRuntime(): void
    {
        $page = $this->createStub(PageInterface::class);
        $context = $this->createMock(BrowserContextInterface::class);
        $context
            ->expects($this->once())
            ->method('close')
        ;
        $session = new BrowserSession('http://localhost:8080', $context, $page);
        $options = BrowserOptions::create()->withAcceptLanguage('de-CH');
        $factory = $this->createMock(BrowserSessionFactoryInterface::class);
        $factory
            ->expects($this->once())
            ->method('create')
            ->with(BrowserType::Firefox, 'http://localhost:8080', $options)
            ->willReturn($session)
        ;

        $factory
            ->expects($this->never())
            ->method('close')
        ;
        $application = new Application(ApplicationConfig::create('http://localhost:8080'), new BrowserRuntime('/unused', $factory), ApplicationRuntime::shared());

        $backend = new BackendBrowser(
            $application->createBrowser(options: $options),
            new BackendLoginSessionCache(
                $application->runtime()->cache,
                new SessionCache($application->runtime()->cache, $application->uri(), new CookieSessionStorage()),
            ),
        );
        $this->assertSame($session, $backend->browser());
        $this->assertSame($page, $application->browserRuntime()->currentPage());
        $application->resetState();
        $this->expectException(\LogicException::class);

        try {
            $application->browserRuntime()->currentPage();
        } finally {
            $application->release();
        }
    }
}
