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
use Contao\E2eTesting\Browser\BrowserOptionsNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Playwright\Configuration\PlaywrightConfig;

class BrowserOptionsNormalizerTest extends TestCase
{
    /**
     * @var array<string, string|false>
     */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (['PW_VIDEO_WIDTH', 'PW_VIDEO_HEIGHT'] as $name) {
            $this->environment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv(false === $value ? $name : $name.'='.$value);
        }
    }

    public function testNormalizesAcceptedLanguagesAndViewport(): void
    {
        $options = BrowserOptions::create()
            ->withAcceptLanguage('de-CH,de,en')
            ->withViewport(1440, 1200)
        ;
        $normalized = (new BrowserOptionsNormalizer())->normalize($options);

        $this->assertSame(['Accept-Language' => 'de-CH,de,en'], $normalized['extraHTTPHeaders']);
        $this->assertSame(['width' => 1440, 'height' => 1200], $normalized['viewport']);
    }

    public function testLeavesUnsetOptionsToPlaywrightDefaults(): void
    {
        $this->assertSame([], (new BrowserOptionsNormalizer())->normalize(BrowserOptions::create()));
    }

    public function testRecordingDefaultsToEffectiveViewport(): void
    {
        $normalizer = new BrowserOptionsNormalizer();
        $config = new PlaywrightConfig(videosDir: 'videos');
        $defaults = $normalizer->normalize(BrowserOptions::create(), $config);
        $custom = $normalizer->normalize(BrowserOptions::create()->withViewport(1440, 1200), $config);

        $this->assertSame(['dir' => 'videos', 'size' => ['width' => 1280, 'height' => 720]], $defaults['recordVideo']);
        $this->assertArrayNotHasKey('viewport', $defaults);
        $this->assertSame(['width' => 1440, 'height' => 1200], $custom['recordVideo']['size']);
        $this->assertSame($custom['viewport'], $custom['recordVideo']['size']);
    }

    public function testEnvironmentOverridesViewportAndExplicitVideoSizeOverridesEnvironment(): void
    {
        putenv('PW_VIDEO_WIDTH=1024');
        putenv('PW_VIDEO_HEIGHT=768');
        $normalizer = new BrowserOptionsNormalizer();
        $config = new PlaywrightConfig(videosDir: 'videos');
        $options = BrowserOptions::create()->withViewport(1440, 1200);
        $normalized = $normalizer->normalize($options, $config);

        $this->assertSame(['width' => 1024, 'height' => 768], $normalized['recordVideo']['size']);
        $this->assertSame(['width' => 1440, 'height' => 1200], $normalized['viewport']);
        putenv('PW_VIDEO_WIDTH=invalid');
        $explicit = $normalizer->normalize($options->withVideoSize(800, 600), $config);
        $this->assertSame(['dir' => 'videos', 'size' => ['width' => 800, 'height' => 600]], $explicit['recordVideo']);
    }

    public function testVideoDimensionsDoNotEnableRecording(): void
    {
        putenv('PW_VIDEO_WIDTH=invalid');
        $options = BrowserOptions::create()->withViewport(1440, 1200)->withVideoSize(800, 600);

        $this->assertSame(
            ['viewport' => ['width' => 1440, 'height' => 1200]],
            (new BrowserOptionsNormalizer())->normalize($options, new PlaywrightConfig()),
        );
        $this->assertSame([], (new BrowserOptionsNormalizer())->normalize(BrowserOptions::create(), new PlaywrightConfig()));
    }

    public function testAcceptsZeroPaddedDecimalEnvironmentDimensions(): void
    {
        putenv('PW_VIDEO_WIDTH=01280');
        putenv('PW_VIDEO_HEIGHT=00720');
        $normalized = (new BrowserOptionsNormalizer())->normalize(BrowserOptions::create(), new PlaywrightConfig(videosDir: 'videos'));

        $this->assertSame(['width' => 1280, 'height' => 720], $normalized['recordVideo']['size']);
    }

    public function testEmptyEnvironmentDimensionsUseViewport(): void
    {
        putenv('PW_VIDEO_WIDTH=');
        putenv('PW_VIDEO_HEIGHT=');
        $normalized = (new BrowserOptionsNormalizer())->normalize(BrowserOptions::create(), new PlaywrightConfig(videosDir: 'videos'));

        $this->assertSame(['width' => 1280, 'height' => 720], $normalized['recordVideo']['size']);
    }

    #[DataProvider('invalidEnvironmentDimensions')]
    public function testRejectsInvalidEnvironmentDimensions(string $width, string $height): void
    {
        putenv('PW_VIDEO_WIDTH='.$width);
        putenv('PW_VIDEO_HEIGHT='.$height);
        $this->expectException(\InvalidArgumentException::class);

        (new BrowserOptionsNormalizer())->normalize(BrowserOptions::create(), new PlaywrightConfig(videosDir: 'videos'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidEnvironmentDimensions(): iterable
    {
        yield 'missing width' => ['', '720'];
        yield 'missing height' => ['1280', ''];
        yield 'zero width' => ['0', '720'];
        yield 'zero height' => ['1280', '000'];
        yield 'negative height' => ['1280', '-1'];
        yield 'decimal' => ['1.5', '720'];
        yield 'text' => ['1280', 'invalid'];
        yield 'whitespace' => [' 1280', '720'];
        yield 'overflow' => [PHP_INT_MAX.'0', '720'];
    }
}
