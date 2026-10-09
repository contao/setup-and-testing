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

    /**
     * @var list<array<string, mixed>>
     */
    private array $messages = [];

    /**
     * @var array<string, string|false>
     */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (['PW_VIDEOS_DIR', 'PW_HEADLESS', 'PW_SLOWMO_MS', 'PW_VIDEO_WIDTH', 'PW_VIDEO_HEIGHT'] as $name) {
            $this->environment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv(false === $value ? $name : $name.'='.$value);
        }
    }

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

    #[DataProvider('recordingLaunchOptions')]
    public function testPropagatesRecordingConfigurationAndPreservesLaunchEnvironment(string $headless, int $slowMo): void
    {
        foreach (['PW_VIDEOS_DIR' => 'videos', 'PW_HEADLESS' => $headless, 'PW_SLOWMO_MS' => (string) $slowMo, 'PW_VIDEO_WIDTH' => '1024', 'PW_VIDEO_HEIGHT' => '768'] as $name => $value) {
            putenv($name.'='.$value);
        }

        $manager = $this->manager();

        try {
            $options = BrowserOptions::create()->withAcceptLanguage('de-CH')->withViewport(1440, 1200)->withVideoSize(1440, 1200);
            $manager->create(BrowserType::Firefox, 'https://example.test', $options)->close();
            $manager->create(BrowserType::Chromium, 'https://example.test', BrowserOptions::create())->close();
            $contexts = array_values(array_filter($this->messages, static fn (array $message): bool => 'newContext' === $message['action']));
            $launches = array_values(array_filter($this->messages, static fn (array $message): bool => 'launch' === $message['action']));

            $this->assertSame(['dir' => 'videos', 'size' => ['width' => 1440, 'height' => 1200]], $contexts[0]['options']['recordVideo']);
            $this->assertSame(['width' => 1440, 'height' => 1200], $contexts[0]['options']['viewport']);
            $this->assertSame(['Accept-Language' => 'de-CH'], $contexts[0]['options']['extraHTTPHeaders']);
            $this->assertSame(['dir' => 'videos', 'size' => ['width' => 1024, 'height' => 768]], $contexts[1]['options']['recordVideo']);

            $this->assertLaunchOptions($launches, 'true' === $headless, $slowMo);
        } finally {
            $manager->close();
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function recordingLaunchOptions(): iterable
    {
        yield 'headed and slowed' => ['false', 250];
        yield 'headless without delay' => ['true', 0];
    }

    public function testInvalidRecordingDimensionsFailBeforeLaunchingBrowser(): void
    {
        putenv('PW_VIDEOS_DIR=videos');
        putenv('PW_VIDEO_WIDTH=1280');
        $manager = $this->manager();

        try {
            try {
                $manager->create(BrowserType::Firefox, 'https://example.test', BrowserOptions::create());
                $this->fail('Expected an incomplete video dimension pair to fail.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame('PW_VIDEO_WIDTH and PW_VIDEO_HEIGHT must both be positive integers.', $exception->getMessage());
            }

            $this->assertSame(0, $this->clientCount);
            $this->assertSame(0, $this->launchCount);
            $this->assertSame(0, $this->contextCount);
        } finally {
            $manager->close();
        }
    }

    public function testVideoDimensionsDoNotEnableRecordingWhenDirectoryIsEmpty(): void
    {
        putenv('PW_VIDEOS_DIR=');
        putenv('PW_VIDEO_WIDTH=invalid');
        $manager = $this->manager();

        try {
            $manager->create(BrowserType::Firefox, 'https://example.test', BrowserOptions::create()->withVideoSize(800, 600))->close();
            $contexts = array_values(array_filter($this->messages, static fn (array $message): bool => 'newContext' === $message['action']));

            $this->assertSame([], $contexts[0]['options']);
        } finally {
            $manager->close();
        }
    }

    /**
     * @param list<array<string, mixed>> $launches
     */
    private function assertLaunchOptions(array $launches, bool $headless, int $slowMo): void
    {
        $this->assertCount(2, $launches);

        foreach ($launches as $launch) {
            $this->assertSame($headless, $launch['options']['headless']);

            if (0 === $slowMo) {
                $this->assertArrayNotHasKey('slowMo', $launch['options']);
            } else {
                $this->assertSame($slowMo, $launch['options']['slowMo']);
            }
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
                fn (array $message): array => $this->respond($message, $connection->connected),
            )
        ;

        return $transport;
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private function respond(array $message, bool $connected): array
    {
        $this->messages[] = $message;

        if (!$connected) {
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
    }
}
