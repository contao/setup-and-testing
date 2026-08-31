<?php

declare(strict_types=1);

namespace Contao\E2eTestBundle\ManagedEdition;

use PHPUnit\Framework\Assert;
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
}
