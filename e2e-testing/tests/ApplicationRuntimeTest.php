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
use PHPUnit\Framework\TestCase;

final class ApplicationRuntimeTest extends TestCase
{
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
}
