<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Browser;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Firefox\FirefoxOptions;

final readonly class BrowserOptionsNormalizer
{
    /**
     * @param list<string> $arguments
     */
    public function forFirefox(BrowserOptions $options, array $arguments): FirefoxOptions
    {
        $firefoxOptions = new FirefoxOptions();
        $firefoxOptions->addArguments($arguments);
        $firefoxOptions->setPreference('intl.accept_languages', $options->acceptLanguage ?? '');
        $firefoxOptions->setPreference('ui.prefersReducedMotion', $this->reducedMotionPreference());

        if (isset($_SERVER['PANTHER_FIREFOX_BINARY'])) {
            $firefoxOptions->setOption('binary', $_SERVER['PANTHER_FIREFOX_BINARY']);
        }

        return $firefoxOptions;
    }

    public function forChrome(BrowserOptions $options): ChromeOptions
    {
        $chromeOptions = new ChromeOptions();
        $chromeOptions->setExperimentalOption('prefs', [
            'intl.accept_languages' => $options->acceptLanguage,
        ]);

        return $chromeOptions;
    }

    private function reducedMotionPreference(): int
    {
        return filter_var($_SERVER['PANTHER_NO_REDUCED_MOTION'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 0 : 1;
    }
}
