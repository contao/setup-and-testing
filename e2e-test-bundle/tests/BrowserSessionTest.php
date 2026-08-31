<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Tests;

use Contao\E2eTestBundle\Browser\BrowserSession;
use PHPUnit\Framework\TestCase;
use Playwright\Browser\BrowserContextInterface;
use Playwright\Exception\DisconnectedException;
use Playwright\Page\PageInterface;

class BrowserSessionTest extends TestCase
{
    public function testResolvesRelativeAndAbsoluteUris(): void
    {
        $context = $this->createStub(BrowserContextInterface::class);
        $page = $this->createStub(PageInterface::class);
        $browser = new BrowserSession('http://localhost:8000/', $context, $page);

        $this->assertSame('http://localhost:8000/contao/login', $browser->uri('/contao/login'));
        $this->assertSame('https://example.com/path', $browser->uri('https://example.com/path'));
    }

    public function testClosesItsContextOnlyOnce(): void
    {
        $context = $this->createMock(BrowserContextInterface::class);
        $context
            ->expects($this->once())
            ->method('close')
        ;
        $browser = new BrowserSession('http://localhost:8000', $context, $this->createStub(PageInterface::class));

        $browser->close();
        $browser->close();
    }

    public function testIgnoresAnAlreadyDisconnectedContextDuringCleanup(): void
    {
        $this->expectNotToPerformAssertions();

        $context = $this->createStub(BrowserContextInterface::class);
        $context
            ->method('close')
            ->willThrowException(new DisconnectedException('Disconnected'))
        ;
        $browser = new BrowserSession('http://localhost:8000', $context, $this->createStub(PageInterface::class));

        $browser->close();
    }
}
