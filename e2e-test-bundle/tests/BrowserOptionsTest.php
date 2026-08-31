<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Tests;

use Contao\E2eTestBundle\Browser\BrowserOptions;
use PHPUnit\Framework\TestCase;

class BrowserOptionsTest extends TestCase
{
    public function testConfiguresAcceptedLanguagesWithoutMutatingTheOriginalOptions(): void
    {
        $options = BrowserOptions::create();
        $configuredOptions = $options->withAcceptLanguage(' de-CH,de,en ');

        $this->assertNull($options->acceptLanguage);
        $this->assertSame('de-CH,de,en', $configuredOptions->acceptLanguage);
    }

    public function testConfiguresViewportWithoutMutatingTheOriginalOptions(): void
    {
        $options = BrowserOptions::create();
        $configuredOptions = $options->withViewport(1440, 1200);

        $this->assertNull($options->viewportWidth);
        $this->assertNull($options->viewportHeight);
        $this->assertSame(1440, $configuredOptions->viewportWidth);
        $this->assertSame(1200, $configuredOptions->viewportHeight);
    }

    public function testRejectsEmptyAcceptedLanguages(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BrowserOptions::create()->withAcceptLanguage('  ');
    }

    public function testRejectsInvalidViewport(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BrowserOptions::create()->withViewport(0, 1200);
    }
}
