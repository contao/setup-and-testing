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
    public function testNormalizesAcceptedLanguagesForFirefox(): void
    {
        $options = BrowserOptions::create()->withAcceptLanguage('de-CH,de,en');
        $normalized = (new BrowserOptionsNormalizer())->forFirefox($options, ['--headless'])->toArray();

        $this->assertSame('de-CH,de,en', $normalized['prefs']['intl.accept_languages']);
        $this->assertSame(['--headless'], $normalized['args']);
    }

    public function testNormalizesAcceptedLanguagesForChrome(): void
    {
        $options = BrowserOptions::create()->withAcceptLanguage('de-CH,de,en');
        $normalized = (new BrowserOptionsNormalizer())->forChrome($options)->toArray();

        $this->assertSame('de-CH,de,en', $normalized['prefs']['intl.accept_languages']);
    }
}
