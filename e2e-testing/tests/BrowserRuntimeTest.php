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

use Contao\E2eTesting\Browser\BrowserOptions;
use Contao\E2eTesting\Browser\BrowserRuntime;
use Contao\E2eTesting\Browser\BrowserSession;
use Contao\E2eTesting\Browser\BrowserSessionFactoryInterface;
use Contao\E2eTesting\Browser\BrowserType;
use PHPUnit\Framework\TestCase;
use Playwright\Browser\BrowserContextInterface;
use Playwright\Page\PageInterface;
use Playwright\Tracing\TracingInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class BrowserRuntimeTest extends TestCase
{
    public function testResetClosesEveryContextAndKeepsTheBrowserEngine(): void
    {
        $factory = $this->createMock(BrowserSessionFactoryInterface::class);
        $firstPage = $this->createStub(PageInterface::class);
        $secondPage = $this->createStub(PageInterface::class);
        $firstContext = $this->createMock(BrowserContextInterface::class);
        $secondContext = $this->createMock(BrowserContextInterface::class);
        $firstContext
            ->expects($this->once())
            ->method('close')
        ;

        $secondContext
            ->expects($this->once())
            ->method('close')
        ;

        $factory
            ->expects($this->exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls(
                new BrowserSession('https://example.test', $firstContext, $firstPage),
                new BrowserSession('https://example.test', $secondContext, $secondPage),
            )
        ;

        $factory
            ->expects($this->never())
            ->method('close')
        ;
        $runtime = new BrowserRuntime('/unused', $factory);

        $runtime->createBrowser('https://example.test');
        $this->assertSame($firstPage, $runtime->currentPage());
        $runtime->createBrowser('https://example.test');
        $this->assertSame($secondPage, $runtime->currentPage());
        $runtime->reset();
        $runtime->reset();

        $this->expectException(\LogicException::class);
        $runtime->currentPage();
    }

    public function testResetClosesRemainingContextsWhenOneFails(): void
    {
        $failure = new \RuntimeException('First context cleanup failed');
        $first = $this->createStub(BrowserContextInterface::class);
        $first
            ->method('close')
            ->willThrowException($failure)
        ;
        $second = $this->createMock(BrowserContextInterface::class);
        $second
            ->expects($this->once())
            ->method('close')
        ;
        $factory = $this->createStub(BrowserSessionFactoryInterface::class);
        $factory
            ->method('create')
            ->willReturnOnConsecutiveCalls(
                new BrowserSession('https://example.test', $first, $this->createStub(PageInterface::class)),
                new BrowserSession('https://example.test', $second, $this->createStub(PageInterface::class)),
            )
        ;
        $runtime = new BrowserRuntime('/unused', $factory);
        $runtime->createBrowser('https://example.test');
        $runtime->createBrowser('https://example.test');

        try {
            $runtime->reset();
            $this->fail('Expected context cleanup to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $runtime->reset();
        $this->assertSame([], $runtime->finishTracing('empty'));
        $this->expectException(\LogicException::class);
        $runtime->currentPage();
    }

    public function testForwardsTheUrlEngineAndOptions(): void
    {
        $options = BrowserOptions::create()->withViewport(800, 600);
        $session = new BrowserSession('https://example.test/app', $this->createStub(BrowserContextInterface::class), $this->createStub(PageInterface::class));
        $factory = $this->createMock(BrowserSessionFactoryInterface::class);
        $factory
            ->expects($this->once())
            ->method('create')
            ->with(BrowserType::Chromium, 'https://example.test/app', $options)
            ->willReturn($session)
        ;
        $runtime = new BrowserRuntime('/unused', $factory);

        $this->assertSame($session, $runtime->createBrowser('https://example.test/app', BrowserType::Chromium, $options));
        $this->assertSame('https://example.test/app/login', $session->uri('/login'));
    }

    public function testWritesDistinctSanitizedTracePathsForMultipleSessions(): void
    {
        $directory = Path::join(sys_get_temp_dir(), 'browser-runtime-'.bin2hex(random_bytes(6)));
        $factory = $this->createMock(BrowserSessionFactoryInterface::class);
        $factory
            ->expects($this->exactly(2))
            ->method('create')
            ->willReturn(
                $this->tracedSession($directory.'/Example-test.zip'),
                $this->tracedSession($directory.'/Example-test-2.zip'),
            )
        ;
        $runtime = new BrowserRuntime($directory, $factory);

        try {
            $runtime->createBrowser('https://example.test');
            $runtime->createBrowser('https://example.test');
            $this->assertSame([$directory.'/Example-test.zip', $directory.'/Example-test-2.zip'], $runtime->finishTracing('Example::test'));
            $runtime->reset();
            $this->assertSame([], $runtime->finishTracing('Empty'));
        } finally {
            $runtime->reset();
            (new Filesystem())->remove($directory);
        }
    }

    private function tracedSession(string $path): BrowserSession
    {
        $tracing = $this->createMock(TracingInterface::class);
        $tracing
            ->expects($this->once())
            ->method('stop')
            ->with(['path' => $path])
        ;
        $context = $this->createStub(BrowserContextInterface::class);
        $context
            ->method('tracing')
            ->willReturn($tracing)
        ;

        return new BrowserSession('https://example.test', $context, $this->createStub(PageInterface::class));
    }
}
