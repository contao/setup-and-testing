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
use Contao\E2eTestBundle\Browser\BrowserOptionsNormalizer;
use PHPUnit\Framework\TestCase;

class BrowserOptionsNormalizerTest extends TestCase
{
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
}
