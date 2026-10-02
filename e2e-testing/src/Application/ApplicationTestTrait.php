<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Application;

use Contao\E2eTesting\Browser\BrowserRuntime;
use Contao\E2eTesting\Browser\PlaywrightManager;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\AfterClass;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\BeforeClass;
use Playwright\Assertions\AssertionOptions;
use Playwright\Assertions\Expect;
use Playwright\Page\PageInterface;

trait ApplicationTestTrait
{
    private static ApplicationInterface|null $application = null;

    private static bool $applicationFresh = false;

    #[BeforeClass]
    public static function createApplication(): void
    {
        self::$application = static::createApplicationConfig()->createApplication();
        self::$applicationFresh = true;
    }

    #[AfterClass]
    public static function releaseApplication(): void
    {
        self::$application?->release();
        self::$application = null;
        self::$applicationFresh = false;
    }

    public function assertSelectorExists(string $selector, string $message = ''): void
    {
        $locator = self::currentBrowserPage()->locator($selector);
        Expect::locator($locator->first())->toBeAttached(new AssertionOptions(message: $message ?: null));
        Assert::assertGreaterThan(0, $locator->count(), $message);
    }

    public function assertSelectorTextContains(string $selector, string $text, string $message = ''): void
    {
        $locator = self::currentBrowserPage()->locator($selector);
        Expect::locator($locator)->toContainText($text, new AssertionOptions(message: $message ?: null));
        Assert::assertGreaterThan(0, $locator->count(), $message);
        Assert::assertStringContainsString($text, $locator->first()->innerText(), $message);
    }

    abstract protected static function createApplicationConfig(): ApplicationConfigInterface;

    #[Before]
    protected function resetApplication(): void
    {
        if (self::$applicationFresh) {
            self::$applicationFresh = false;

            return;
        }

        if ($this->shouldResetApplication()) {
            self::application()->resetState();
        }
    }

    protected function shouldResetApplication(): bool
    {
        return true;
    }

    protected static function browserRuntime(): BrowserRuntime|null
    {
        return self::$application?->browserRuntime();
    }

    protected static function application(): ApplicationInterface
    {
        if (!self::$application) {
            throw new \LogicException('The application has not been configured yet.');
        }

        return self::$application;
    }

    #[After]
    protected function finishApplicationTracing(): void
    {
        if ('always' === PlaywrightManager::traceMode()) {
            $this->writeBrowserTraces();
        }
    }

    /**
     * @throws \Throwable
     */
    protected function onNotSuccessfulTest(\Throwable $t): never
    {
        if ('on-failure' === PlaywrightManager::traceMode()) {
            $this->writeBrowserTraces();
        }

        parent::onNotSuccessfulTest($t);
    }

    private static function currentBrowserPage(): PageInterface
    {
        return self::browserRuntime()?->currentPage() ?? throw new \LogicException('The browser runtime has not been created yet.');
    }

    private function writeBrowserTraces(): void
    {
        static $count = 0;

        foreach (self::browserRuntime()?->finishTracing(static::class.'-'.++$count) ?? [] as $path) {
            fwrite(STDERR, \sprintf("\nPlaywright trace: %s\nOpen it with: npx playwright show-trace %s\n", $path, escapeshellarg($path)));
        }
    }
}
