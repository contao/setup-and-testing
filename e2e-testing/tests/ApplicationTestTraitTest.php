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

use Contao\E2eTesting\Application\Application;
use Contao\E2eTesting\Application\ApplicationConfig;
use Contao\E2eTesting\Application\ApplicationConfigInterface;
use Contao\E2eTesting\Application\ApplicationInterface;
use Contao\E2eTesting\Application\ApplicationRuntime;
use Contao\E2eTesting\Application\ApplicationTestTrait;
use Contao\E2eTesting\Browser\BrowserRuntime;
use Contao\E2eTesting\Browser\BrowserSession;
use Contao\E2eTesting\Browser\BrowserSessionFactoryInterface;
use PHPUnit\Framework\TestCase;
use Playwright\Browser\BrowserContextInterface;
use Playwright\Page\PageInterface;

class ApplicationTestTraitTest extends TestCase
{
    use ApplicationTestTrait;

    private static ApplicationInterface|null $configuredApplication = null;

    private bool $resetEnabled = true;

    public function testAcceptsACustomConfigurationThroughTheSharedLifecycle(): void
    {
        $this->assertInstanceOf(Application::class, self::application());
        $this->assertSame([], self::application()->browserRuntime()->finishTracing('empty'));
    }

    public function testDelegatesInterTestResetToTheConfiguredApplication(): void
    {
        $application = $this->createMock(ApplicationInterface::class);
        $application
            ->method('browserRuntime')
            ->willReturn(new BrowserRuntime('/unused', $this->createStub(BrowserSessionFactoryInterface::class)))
        ;

        $application
            ->expects($this->once())
            ->method('resetState')
        ;

        $application
            ->expects($this->once())
            ->method('release')
        ;
        self::releaseApplication();
        self::$configuredApplication = $application;
        self::createApplication();

        try {
            $this->resetApplication();
            $this->resetApplication();
        } finally {
            self::releaseApplication();
            self::$configuredApplication = null;
            self::createApplication();
        }
    }

    public function testSkippingApplicationResetsStillClosesPreviousContexts(): void
    {
        $context = $this->createMock(BrowserContextInterface::class);
        $context
            ->expects($this->once())
            ->method('close')
        ;
        $factory = $this->createStub(BrowserSessionFactoryInterface::class);
        $factory
            ->method('create')
            ->willReturn(new BrowserSession('http://localhost:8080', $context, $this->createStub(PageInterface::class)))
        ;
        $runtime = new BrowserRuntime('/unused', $factory);
        self::releaseApplication();
        self::$configuredApplication = new Application(ApplicationConfig::create('http://localhost:8080'), $runtime, ApplicationRuntime::shared());
        self::createApplication();
        $this->resetApplication();
        self::application()->createBrowser();
        $this->resetEnabled = false;

        try {
            $this->resetApplication();
            $this->expectException(\LogicException::class);
            $runtime->currentPage();
        } finally {
            self::releaseApplication();
            self::$configuredApplication = null;
            self::createApplication();
        }
    }

    protected function shouldResetApplication(): bool
    {
        return $this->resetEnabled;
    }

    protected static function createApplicationConfig(): ApplicationConfigInterface
    {
        return new class(self::$configuredApplication) implements ApplicationConfigInterface {
            public function __construct(private readonly ApplicationInterface|null $application)
            {
            }

            public function createApplication(ApplicationRuntime $runtime): ApplicationInterface
            {
                return $this->application ?? ApplicationConfig::create('http://localhost:8080')->createApplication($runtime);
            }
        };
    }
}
