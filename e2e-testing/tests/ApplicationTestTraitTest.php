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
use Contao\E2eTesting\Application\ApplicationTestTrait;
use PHPUnit\Framework\TestCase;

class ApplicationTestTraitTest extends TestCase
{
    use ApplicationTestTrait;

    private static ApplicationInterface|null $configuredApplication = null;

    public function testAcceptsACustomConfigurationThroughTheSharedLifecycle(): void
    {
        $this->assertInstanceOf(Application::class, self::application());
        $this->assertSame([], self::application()->browserRuntime()->finishTracing('empty'));
    }

    public function testDelegatesInterTestResetToTheConfiguredApplication(): void
    {
        $application = $this->createMock(ApplicationInterface::class);
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

    protected static function createApplicationConfig(): ApplicationConfigInterface
    {
        return new class(self::$configuredApplication) implements ApplicationConfigInterface {
            public function __construct(private readonly ApplicationInterface|null $application)
            {
            }

            public function createApplication(): ApplicationInterface
            {
                return $this->application ?? ApplicationConfig::create('http://localhost:8080')->createApplication();
            }
        };
    }
}
