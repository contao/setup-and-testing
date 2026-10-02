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
use Contao\E2eTesting\Browser\BrowserOptions;
use Contao\E2eTesting\Browser\BrowserRuntime;
use Contao\E2eTesting\Browser\BrowserSession;
use Contao\E2eTesting\Browser\BrowserSessionFactoryInterface;
use Contao\E2eTesting\Browser\BrowserType;
use PHPUnit\Framework\TestCase;
use Playwright\Browser\BrowserContextInterface;
use Playwright\Page\PageInterface;

class ApplicationTest extends TestCase
{
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
            ->expects($this->once())
            ->method('close')
        ;
        $application = new Application(ApplicationConfig::create('http://localhost:8080'), new BrowserRuntime('/unused', $factory));

        $backend = $application->createBackendBrowser(options: $options);
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
