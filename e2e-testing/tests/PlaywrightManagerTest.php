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
use Contao\E2eTesting\Browser\BrowserOptionsNormalizer;
use Contao\E2eTesting\Browser\BrowserType;
use Contao\E2eTesting\Browser\PlaywrightClientFactoryInterface;
use Contao\E2eTesting\Browser\PlaywrightManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Playwright\Configuration\PlaywrightConfig;
use Playwright\Exception\DisconnectedException;
use Playwright\PlaywrightClient;
use Playwright\Transport\TransportInterface;
use Psr\Log\NullLogger;

final class PlaywrightManagerTest extends TestCase
{
    /**
     * @var list<object{connected: bool}&\stdClass>
     */
    private array $connections = [];

    private string|null $failureAction = null;

    private bool $failLaunch = false;

    private bool $failCleanup = false;

    private int $closedContexts = 0;

    private int $clientCount = 0;

    private int $launchCount = 0;

    private int $contextCount = 0;

    public function testClosedManagersCannotLaunchNewBrowsers(): void
    {
        $manager = $this->manager();
        $manager->close();
        $manager->close();
        $this->assertSame(0, $this->clientCount);
        $this->expectException(\LogicException::class);
        $manager->create(BrowserType::Firefox, 'https://example.test', BrowserOptions::create());
    }

    public function testReusesConnectedBrowsersAndReplacesClosedBrowsers(): void
    {
        $manager = $this->manager();

        try {
            $first = $manager->create(BrowserType::Firefox, 'https://example.test', BrowserOptions::create());
            $browser = $first->context()->browser();
            $first->close();
            $second = $manager->create(BrowserType::Firefox, 'https://example.test', BrowserOptions::create());
            $this->assertSame($browser, $second->context()->browser());
            $browser->close();
            $second->close();
            $third = $manager->create(BrowserType::Firefox, 'https://example.test', BrowserOptions::create());

            $this->assertNotSame($browser, $third->context()->browser());
            $this->assertSame(2, $this->launchCount);
            $this->assertSame(2, $this->clientCount);
            $third->close();
        } finally {
            $manager->close();
        }
    }

    public function testDisconnectedTransportsAreReplaced(): void
    {
        $manager = $this->manager();

        try {
            $first = $manager->create(BrowserType::Firefox, 'https://example.test', BrowserOptions::create());
            $this->connections[0]->connected = false;
            $first->close();
            $second = $manager->create(BrowserType::Firefox, 'https://example.test', BrowserOptions::create());

            $this->assertNotSame($first->context()->browser(), $second->context()->browser());
            $this->assertTrue($second->context()->browser()->isConnected());
            $this->assertSame(2, $this->clientCount);
            $this->assertSame(2, $this->launchCount);
            $second->close();
        } finally {
            $manager->close();
        }
    }

    public function testDisconnectedTransportsAreReplacedBeforeLaunchingAnotherEngine(): void
    {
        $manager = $this->manager();

        try {
            $first = $manager->create(BrowserType::Firefox, 'https://example.test', BrowserOptions::create());
            $this->connections[0]->connected = false;
            $first->close();
            $second = $manager->create(BrowserType::Chromium, 'https://example.test', BrowserOptions::create());

            $this->assertTrue($second->context()->browser()->isConnected());
            $this->assertSame(2, $this->clientCount);
            $second->close();
        } finally {
            $manager->close();
        }
    }

    #[DataProvider('initializationFailures')]
    public function testFailedInitializationClosesItsContext(string $action, bool $failCleanup): void
    {
        $this->failureAction = $action;
        $this->failCleanup = $failCleanup;
        $previousTraceMode = getenv('CONTAO_E2E_TRACE');
        putenv('CONTAO_E2E_TRACE=always');
        $manager = $this->manager();

        try {
            try {
                $manager->create(BrowserType::Firefox, 'https://example.test', BrowserOptions::create());
                $this->fail('Expected session initialization to fail.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Session setup failed', $exception->getMessage());
            }

            $this->assertSame(1, $this->closedContexts);
            $this->failureAction = null;
            $this->failCleanup = false;
            $this->loadContexts($manager, 50);
            $this->assertSame(2, $this->clientCount);
        } finally {
            $manager->close();
            putenv(false === $previousTraceMode ? 'CONTAO_E2E_TRACE' : 'CONTAO_E2E_TRACE='.$previousTraceMode);
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function initializationFailures(): iterable
    {
        yield 'timeout' => ['context.setDefaultTimeout', false];
        yield 'tracing' => ['tracingStart', false];
        yield 'page' => ['context.newPage', false];
        yield 'media' => ['page.emulateMedia', false];
        yield 'cleanup also fails' => ['context.newPage', true];
    }

    public function testFailedFirstLaunchDoesNotPoisonTheSharedClient(): void
    {
        $manager = $this->manager();
        $this->failLaunch = true;

        try {
            try {
                $manager->create(BrowserType::Firefox, 'https://example.test', BrowserOptions::create());
                $this->fail('Expected the browser launch to fail.');
            } catch (DisconnectedException $exception) {
                $this->assertSame('Transport stopped during launch', $exception->getMessage());
            }

            $this->failLaunch = false;
            $this->loadContexts($manager, 1);
            $this->assertSame(2, $this->clientCount);
        } finally {
            $manager->close();
        }
    }

    public function testRecoveryPreservesOtherConnectedBrowserEngines(): void
    {
        $manager = $this->manager();

        try {
            $chromium = $manager->create(BrowserType::Chromium, 'https://example.test', BrowserOptions::create());
            $firefox = $manager->create(BrowserType::Firefox, 'https://example.test', BrowserOptions::create());
            $firefox->context()->browser()->close();
            $firefox->close();
            $replacement = $manager->create(BrowserType::Firefox, 'https://example.test', BrowserOptions::create());

            $this->assertTrue($chromium->context()->browser()->isConnected());
            $this->assertFalse($chromium->isClosed());
            $this->assertNotSame($firefox->context()->browser(), $replacement->context()->browser());
            $this->assertSame(1, $this->clientCount);
            $this->assertSame(3, $this->launchCount);
            $chromium->close();
            $replacement->close();
        } finally {
            $manager->close();
        }
    }

    public function testIdleRecyclingReleasesRetainedContextsAndPages(): void
    {
        $manager = $this->manager();

        try {
            $session = $manager->create(BrowserType::Firefox, 'https://example.test', BrowserOptions::create());
            $context = \WeakReference::create($session->context());
            $page = \WeakReference::create($session->page());
            $session->close();
            unset($session);
            $this->loadContexts($manager, 49);
            gc_collect_cycles();
            $this->assertNotNull($context->get());
            $this->assertSame(1, $this->launchCount);
            $this->loadContexts($manager, 1);
            gc_collect_cycles();

            $this->assertNull($context->get());
            $this->assertNull($page->get());
            $this->assertSame(2, $this->clientCount);
            $this->assertSame(2, $this->launchCount);
        } finally {
            $manager->close();
        }
    }

    public function testRecyclingWaitsUntilAllSessionsAreClosed(): void
    {
        $manager = $this->manager();

        try {
            $active = $manager->create(BrowserType::Firefox, 'https://example.test', BrowserOptions::create());
            $this->loadContexts($manager, 50);
            $this->assertSame(1, $this->launchCount);
            $this->assertTrue($active->context()->browser()->isConnected());
            $this->assertFalse($active->isClosed());
            $active->close();
            $this->loadContexts($manager, 1);

            $this->assertSame(2, $this->launchCount);
            $this->assertSame(2, $this->clientCount);
        } finally {
            $manager->close();
        }
    }

    private function loadContexts(PlaywrightManager $manager, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $manager->create(BrowserType::Firefox, 'https://example.test', BrowserOptions::create())->close();
        }
    }

    private function manager(): PlaywrightManager
    {
        $factory = $this->createStub(PlaywrightClientFactoryInterface::class);
        $factory
            ->method('create')
            ->willReturnCallback(
                function (PlaywrightConfig $config): PlaywrightClient {
                    ++$this->clientCount;

                    return new PlaywrightClient($this->transport(), new NullLogger(), $config);
                },
            )
        ;

        return new PlaywrightManager(new BrowserOptionsNormalizer(), $factory);
    }

    private function transport(): TransportInterface
    {
        $connection = (object) ['connected' => true];
        $this->connections[] = $connection;
        $transport = $this->createStub(TransportInterface::class);
        $transport
            ->method('isConnected')
            ->willReturnCallback(static fn (): bool => $connection->connected)
        ;

        $transport
            ->method('send')
            ->willReturnCallback(
                function (array $message) use ($connection): array {
                    if (!$connection->connected) {
                        throw new DisconnectedException('Transport stopped');
                    }

                    if ($this->failLaunch && 'launch' === $message['action']) {
                        throw new DisconnectedException('Transport stopped during launch');
                    }

                    if ('context.close' === $message['action']) {
                        ++$this->closedContexts;

                        if ($this->failCleanup) {
                            throw new \RuntimeException('Context cleanup failed');
                        }
                    }

                    if ($this->failureAction === $message['action']) {
                        throw new \RuntimeException('Session setup failed');
                    }

                    return match ($message['action']) {
                        'launch' => ['browserId' => 'browser-'.++$this->launchCount, 'defaultContextId' => 'default-'.$this->launchCount, 'version' => 'test'],
                        'newContext' => ['contextId' => 'context-'.++$this->contextCount],
                        'context.newPage' => ['pageId' => 'page-'.$message['contextId']],
                        default => [],
                    };
                },
            )
        ;

        return $transport;
    }
}
