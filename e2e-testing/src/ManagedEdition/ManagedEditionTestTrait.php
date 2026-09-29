<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\ManagedEdition;

use Contao\E2eTesting\Browser\PlaywrightManager;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\AfterClass;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\BeforeClass;
use Playwright\Assertions\AssertionOptions;
use Playwright\Assertions\Expect;

trait ManagedEditionTestTrait
{
    private static ManagedEdition|null $contaoManagedEdition = null;

    private static bool $contaoManagedEditionFresh = false;

    #[BeforeClass]
    public static function createContaoManagedEdition(): void
    {
        self::$contaoManagedEdition = (new ManagedEditionFactory())->create(static::createManagedEditionConfig());
        self::$contaoManagedEdition->startServer();

        self::$contaoManagedEditionFresh = true;
    }

    #[AfterClass]
    public static function releaseContaoManagedEdition(): void
    {
        self::$contaoManagedEdition?->release();
        self::$contaoManagedEdition = null;
        self::$contaoManagedEditionFresh = false;
    }

    public function assertSelectorExists(string $selector, string $message = ''): void
    {
        $locator = self::managedEdition()->currentPage()->locator($selector);
        Expect::locator($locator->first())->toBeAttached(new AssertionOptions(message: $message ?: null));
        Assert::assertGreaterThan(0, $locator->count(), $message);
    }

    public function assertSelectorTextContains(string $selector, string $text, string $message = ''): void
    {
        $locator = self::managedEdition()->currentPage()->locator($selector);
        Expect::locator($locator)->toContainText($text, new AssertionOptions(message: $message ?: null));
        Assert::assertGreaterThan(0, $locator->count(), $message);
        Assert::assertStringContainsString($text, $locator->first()->innerText(), $message);
    }

    abstract protected static function createManagedEditionConfig(): ManagedEditionConfig;

    #[Before]
    protected function resetContaoManagedEdition(): void
    {
        if (self::$contaoManagedEditionFresh) {
            self::$contaoManagedEditionFresh = false;

            return;
        }

        if ($this->shouldResetContaoManagedEdition()) {
            self::managedEdition()->resetDatabase();
        }
    }

    #[After]
    protected function finishContaoTracing(): void
    {
        if ('always' === PlaywrightManager::traceMode()) {
            $this->writeContaoTraces();
        }
    }

    /**
     * @throws \Throwable
     */
    protected function onNotSuccessfulTest(\Throwable $t): never
    {
        if ('on-failure' === PlaywrightManager::traceMode()) {
            $this->writeContaoTraces();
        }

        parent::onNotSuccessfulTest($t);
    }

    protected function shouldResetContaoManagedEdition(): bool
    {
        return true;
    }

    protected static function managedEdition(): ManagedEdition
    {
        if (!self::$contaoManagedEdition) {
            throw new \LogicException('The managed Contao edition has not been created yet.');
        }

        return self::$contaoManagedEdition;
    }

    private function writeContaoTraces(): void
    {
        static $count = 0;

        foreach (self::$contaoManagedEdition?->finishTracing(static::class.'-'.++$count) ?? [] as $path) {
            fwrite(STDERR, \sprintf("\nPlaywright trace: %s\nOpen it with: npx playwright show-trace %s\n", $path, escapeshellarg($path)));
        }
    }
}
