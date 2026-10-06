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

use Contao\E2eTesting\Application\ApplicationConfig;
use Contao\E2eTesting\Application\ApplicationRuntime;
use Contao\E2eTesting\Browser\BrowserSession;
use Contao\E2eTesting\Browser\BrowserSessionFactoryInterface;
use Contao\E2eTesting\Browser\BrowserType;
use Contao\InstallationRecipe\Cache\InMemoryCache;
use PHPUnit\Framework\TestCase;
use Playwright\Browser\BrowserContextInterface;
use Playwright\Page\PageInterface;

final class ApplicationRuntimeTest extends TestCase
{
    public function testApplicationsReuseTheFactoryWithSeparateContextsAfterRelease(): void
    {
        $firstSession = $this->session();
        $secondSession = $this->session();
        $factory = $this->createMock(BrowserSessionFactoryInterface::class);
        $factory
            ->expects($this->exactly(2))
            ->method('create')
            ->with(BrowserType::Firefox, 'http://localhost:8080', $this->anything())
            ->willReturnOnConsecutiveCalls($firstSession, $secondSession)
        ;

        $factory
            ->expects($this->once())
            ->method('close')
        ;
        $runtime = new ApplicationRuntime(new InMemoryCache(), $factory);
        $config = ApplicationConfig::create('http://localhost:8080');
        $first = $runtime->createApplication($config);

        $this->assertSame($firstSession, $first->createBrowser());
        $first->release();
        $second = $runtime->createApplication($config);

        try {
            $this->assertSame($secondSession, $second->createBrowser());
            $this->assertNotSame($firstSession->context(), $secondSession->context());
            $this->assertNotSame($first->browserRuntime(), $second->browserRuntime());
        } finally {
            $second->release();
            $runtime->close();
            $runtime->close();
        }
    }

    public function testDestroyingTheRuntimeClosesItsBrowserProcesses(): void
    {
        $factory = $this->createMock(BrowserSessionFactoryInterface::class);
        $factory
            ->expects($this->once())
            ->method('close')
        ;
        $runtime = new ApplicationRuntime(new InMemoryCache(), $factory);
        unset($runtime);
    }

    public function testClosedRuntimesCannotCreateApplications(): void
    {
        $runtime = ApplicationRuntime::create();
        $runtime->close();

        $this->expectException(\LogicException::class);
        $runtime->createApplication(ApplicationConfig::create('http://localhost:8080'));
    }

    public function testExistingApplicationsCannotCreateBrowsersAfterRuntimeClose(): void
    {
        $runtime = ApplicationRuntime::create();
        $application = $runtime->createApplication(ApplicationConfig::create('http://localhost:8080'));
        $runtime->close();

        try {
            $this->expectException(\LogicException::class);
            $application->createBrowser();
        } finally {
            $application->release();
        }
    }

    public function testApplicationsShareCachedValuesAcrossResetsAndRelease(): void
    {
        $runtime = ApplicationRuntime::create();
        $first = $runtime->createApplication(ApplicationConfig::create('http://localhost:8080'));
        $second = $runtime->createApplication(ApplicationConfig::create('http://localhost:8081'));
        $value = new \stdClass();

        try {
            $first->runtime()->cache->set('custom.value', $value);
            $first->resetState();
            $first->release();

            $this->assertSame($runtime, $first->runtime());
            $this->assertSame($runtime, $second->runtime());
            $this->assertSame($value, $second->runtime()->cache->get('custom.value'));
            $runtime->cache->clear();
            $this->assertFalse($second->runtime()->cache->has('custom.value'));
        } finally {
            $first->release();
            $second->release();
        }
    }

    public function testSharedProcessRuntimeCreatesApplicationsWithTheSameCache(): void
    {
        $first = ApplicationConfig::create('http://localhost:8080')->createApplication(ApplicationRuntime::shared());
        $second = ApplicationConfig::create('http://localhost:8081')->createApplication(ApplicationRuntime::shared());

        try {
            $this->assertSame(ApplicationRuntime::shared(), $first->runtime());
            $this->assertSame($first->runtime(), $second->runtime());
        } finally {
            $first->release();
            $second->release();
        }
    }

    public function testSeparateExplicitRuntimesKeepCachedValuesIsolated(): void
    {
        $config = ApplicationConfig::create('http://localhost:8080');
        $first = $config->createApplication(ApplicationRuntime::create());
        $second = $config->createApplication(ApplicationRuntime::create());

        try {
            $first->runtime()->cache->set('custom.value', 'first');

            $this->assertNotSame($first->runtime(), $second->runtime());
            $this->assertFalse($second->runtime()->cache->has('custom.value'));
        } finally {
            $first->release();
            $second->release();
        }
    }

    private function session(): BrowserSession
    {
        $context = $this->createMock(BrowserContextInterface::class);
        $context
            ->expects($this->once())
            ->method('close')
        ;

        return new BrowserSession('http://localhost:8080', $context, $this->createStub(PageInterface::class));
    }
}
