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

use Playwright\Browser\BrowserInterface;
use Playwright\Configuration\PlaywrightConfig;
use Playwright\Configuration\PlaywrightConfigBuilder;
use Playwright\Exception\PlaywrightExceptionInterface;
use Playwright\PlaywrightClient;
use Playwright\PlaywrightFactory;

final class PlaywrightManager
{
    private PlaywrightClient|null $playwright = null;

    private PlaywrightConfig|null $config = null;

    /**
     * @var array<string, BrowserInterface>
     */
    private array $browsers = [];

    public function __construct(private readonly BrowserOptionsNormalizer $optionsNormalizer = new BrowserOptionsNormalizer())
    {
    }

    public function create(BrowserType $type, string $baseUri, BrowserOptions $options): BrowserSession
    {
        $context = $this->browser($type)->newContext($this->optionsNormalizer->normalize($options));
        $context->setDefaultTimeout($this->config()->timeoutMs);

        if (self::traceMode()) {
            $context->tracing()->start(['screenshots' => true, 'snapshots' => true, 'sources' => true]);
        }

        $page = $context->newPage();
        $page->emulateMedia(['reducedMotion' => $this->reducedMotion()]);

        return new BrowserSession($baseUri, $context, $page);
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
    }

    private function browser(BrowserType $type): BrowserInterface
    {
        return $this->browsers[$type->value()] ??= $this->launch($type);
    }

    private function launch(BrowserType $type): BrowserInterface
    {
        // Never go below 30 seconds for the transport, so a low PW_TIMEOUT_MS does not
        // break launching the browser
        $playwright = $this->playwright ??= PlaywrightFactory::create(
            PlaywrightConfigBuilder::fromEnv()->withTimeoutMs(max($this->config()->timeoutMs, 30_000))->build(),
        );

        return match ($type) {
            BrowserType::Chromium => $playwright->chromium()->launch(),
            BrowserType::Firefox => $playwright->firefox()->launch(),
            BrowserType::WebKit => $playwright->webkit()->launch(),
        };
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
