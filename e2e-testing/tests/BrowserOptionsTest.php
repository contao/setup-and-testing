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

use Contao\E2eTesting\Browser\BrowserOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BrowserOptionsTest extends TestCase
{
    public function testConfiguresAcceptedLanguagesWithoutMutatingTheOriginalOptions(): void
    {
        $options = BrowserOptions::create();
        $configuredOptions = $options->withAcceptLanguage(' de-CH,de,en ');

        $this->assertNull($options->acceptLanguage());
        $this->assertSame('de-CH,de,en', $configuredOptions->acceptLanguage());
    }

    public function testConfiguresViewportWithoutMutatingTheOriginalOptions(): void
    {
        $options = BrowserOptions::create();
        $configuredOptions = $options->withViewport(1440, 1200);

        $this->assertNull($options->viewportWidth());
        $this->assertNull($options->viewportHeight());
        $this->assertSame(1440, $configuredOptions->viewportWidth());
        $this->assertSame(1200, $configuredOptions->viewportHeight());
    }

    public function testPreservesOtherOptionsWhenChangingConfiguration(): void
    {
        $original = BrowserOptions::create()->withAcceptLanguage('de-CH')->withViewport(800, 600);
        $configured = $original->withAcceptLanguage('en')->withViewport(1440, 1200);

        $this->assertSame('de-CH', $original->acceptLanguage());
        $this->assertSame(800, $original->viewportWidth());
        $this->assertSame(600, $original->viewportHeight());
        $this->assertSame('en', $configured->acceptLanguage());
        $this->assertSame(1440, $configured->viewportWidth());
        $this->assertSame(1200, $configured->viewportHeight());
        $this->assertSame(800, $original->withAcceptLanguage('en')->viewportWidth());
        $this->assertSame('de-CH', $original->withViewport(1440, 1200)->acceptLanguage());
    }

    public function testConfiguresVideoSizeWithoutMutatingOtherOptions(): void
    {
        $original = BrowserOptions::create()->withViewport(1440, 1200)->withAcceptLanguage('de-CH');
        $configured = $original->withVideoSize(1280, 720);

        $this->assertNull($original->videoWidth());
        $this->assertNull($original->videoHeight());
        $this->assertSame(1280, $configured->videoWidth());
        $this->assertSame(720, $configured->videoHeight());
        $this->assertSame(1440, $configured->viewportWidth());
        $this->assertSame('de-CH', $configured->acceptLanguage());
        $this->assertSame(1280, $configured->withViewport(800, 600)->videoWidth());
        $this->assertSame(720, $configured->withAcceptLanguage('en')->videoHeight());
    }

    #[DataProvider('invalidVideoSizes')]
    public function testRejectsInvalidVideoSizes(int $width, int $height): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BrowserOptions::create()->withVideoSize($width, $height);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalidVideoSizes(): iterable
    {
        yield 'zero width' => [0, 720];
        yield 'negative width' => [-1, 720];
        yield 'zero height' => [1280, 0];
        yield 'negative height' => [1280, -1];
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
