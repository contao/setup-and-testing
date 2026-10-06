<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Browser;

use Playwright\Browser\BrowserContextInterface;
use Playwright\Browser\BrowserInterface;
use Playwright\Configuration\PlaywrightConfig;
use Playwright\Configuration\PlaywrightConfigBuilder;
use Playwright\Exception\PlaywrightExceptionInterface;
use Playwright\PlaywrightClient;

final class PlaywrightManager implements BrowserSessionFactoryInterface
{
    private const MAX_CONTEXTS = 50;

    private bool $closed = false;

    private int $contextCount = 0;

    /**
     * @var \WeakMap<BrowserSession, true>
     */
    private \WeakMap $sessions;

    private PlaywrightClient|null $playwright = null;

    private PlaywrightConfig|null $config = null;

    /**
     * @var array<string, BrowserInterface>
     */
    private array $browsers = [];

    public function __construct(
        private readonly BrowserOptionsNormalizer $optionsNormalizer,
        private readonly PlaywrightClientFactoryInterface $clientFactory,
    ) {
        $this->sessions = new \WeakMap();
    }

    public function create(BrowserType $type, string $baseUri, BrowserOptions $options): BrowserSession
    {
        if ($this->closed) {
            throw new \LogicException('The Playwright manager has been closed.');
        }

        $this->recycleIfIdle();
        $context = $this->browser($type)->newContext($this->optionsNormalizer->normalize($options));
        ++$this->contextCount;

        try {
            $session = $this->initializeSession($context, $baseUri);
        } catch (\Throwable $exception) {
            $this->closeUninitializedContext($context);

            throw $exception;
        }

        $this->sessions[$session] = true;

        return $session;
    }

    public static function traceMode(): string|null
    {
        return match (getenv('CONTAO_E2E_TRACE')) {
            'always' => 'always',
            'on-failure' => 'on-failure',
            default => null,
        };
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->reset();
    }

    private function reset(): void
    {
        foreach ($this->browsers as $browser) {
            try {
                $browser->close();
            } catch (PlaywrightExceptionInterface) {
                // The browser or Playwright process has already stopped.
            }
        }

        $this->browsers = [];

        try {
            $this->playwright?->close();
        } catch (PlaywrightExceptionInterface) {
            // The Playwright process has already stopped.
        }

        $this->playwright = null;
        $this->contextCount = 0;
        $this->sessions = new \WeakMap();
    }

    private function initializeSession(BrowserContextInterface $context, string $baseUri): BrowserSession
    {
        $context->setDefaultTimeout($this->config()->timeoutMs);

        if (self::traceMode()) {
            $context->tracing()->start(['screenshots' => true, 'snapshots' => true, 'sources' => true]);
        }

        $page = $context->newPage();
        $page->emulateMedia(['reducedMotion' => $this->reducedMotion()]);

        return new BrowserSession($baseUri, $context, $page);
    }

    private function closeUninitializedContext(BrowserContextInterface $context): void
    {
        try {
            $context->close();
        } catch (\Throwable) {
            // Preserve the setup failure if closing the partial context also fails.
        }
    }

    private function recycleIfIdle(): void
    {
        if ($this->contextCount < self::MAX_CONTEXTS) {
            return;
        }

        foreach ($this->sessions as $session => $active) {
            if (!$session->isClosed()) {
                return;
            }
        }

        // Playwright PHP retains closed contexts and event dispatchers. Recycling the
        // client when idle bounds that retention without touching active sessions.
        $this->reset();
    }

    private function browser(BrowserType $type): BrowserInterface
    {
        if ($this->browsers && !$this->hasConnectedBrowser()) {
            $this->reset();
        }

        $browser = $this->browsers[$type->value()] ?? null;

        if ($browser && !$browser->isConnected()) {
            unset($this->browsers[$type->value()]);
        }

        return $this->browsers[$type->value()] ??= $this->launch($type);
    }

    private function launch(BrowserType $type): BrowserInterface
    {
        $playwright = $this->playwright ??= $this->clientFactory->create($this->config());

        try {
            return match ($type) {
                BrowserType::Chromium => $playwright->chromium()->launch(),
                BrowserType::Firefox => $playwright->firefox()->launch(),
                BrowserType::WebKit => $playwright->webkit()->launch(),
            };
        } catch (PlaywrightExceptionInterface $exception) {
            if (!$this->hasConnectedBrowser()) {
                $this->reset();
            }

            throw $exception;
        }
    }

    private function hasConnectedBrowser(): bool
    {
        foreach ($this->browsers as $browser) {
            if ($browser->isConnected()) {
                return true;
            }
        }

        return false;
    }

    private function config(): PlaywrightConfig
    {
        return $this->config ??= PlaywrightConfigBuilder::fromEnv()->build();
    }

    private function reducedMotion(): string
    {
        return filter_var($_SERVER['PLAYWRIGHT_NO_REDUCED_MOTION'] ?? false, FILTER_VALIDATE_BOOLEAN)
            ? 'no-preference'
            : 'reduce';
    }
}
