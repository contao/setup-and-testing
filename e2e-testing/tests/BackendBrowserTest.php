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

use Contao\E2eTesting\Browser\BackendBrowser;
use Contao\E2eTesting\Browser\BrowserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Playwright\Browser\BrowserContextInterface;
use Playwright\Page\PageInterface;

final class BackendBrowserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function navigationModes(): iterable
    {
        yield 'either' => ['waitForNavigation', '(marker) => window[marker] !== false'];
        yield 'turbo' => ['waitForTurboNavigation', '(marker) => window[marker] === true'];
        yield 'full page' => ['waitForFullNavigation', '(marker) => window[marker] === undefined'];
    }

    #[DataProvider('navigationModes')]
    public function testWaitsForTheSelectedNavigation(string $method, string $predicate): void
    {
        $page = $this->createMock(PageInterface::class);
        $page
            ->expects($this->once())
            ->method('evaluate')
            ->with($this->stringContains('turbo:render'), $this->anything())
        ;

        $page
            ->expects($this->once())
            ->method('waitForFunction')
            ->with($predicate, $this->anything())
        ;

        $browser = new BackendBrowser(new BrowserSession('http://localhost:8000', $this->createStub(BrowserContextInterface::class), $page));
        $actionRan = false;
        $browser->{$method}(
            static function () use (&$actionRan): void {
                $actionRan = true;
            },
        );

        $this->assertTrue($actionRan);
    }
}
