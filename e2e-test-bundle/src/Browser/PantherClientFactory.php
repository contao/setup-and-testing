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
use Symfony\Component\Panther\Client;

final readonly class PantherClientFactory
{
    public function __construct(private BrowserOptionsNormalizer $optionsNormalizer = new BrowserOptionsNormalizer())
    {
    }

    public function createFirefox(string $baseUri, BrowserOptions $options): Client
    {
        if (null === $options->acceptLanguage) {
            return Client::createFirefoxClient(null, null, [], $baseUri);
        }

        $arguments = $this->firefoxArguments();
        $firefoxOptions = $this->optionsNormalizer->forFirefox($options, $arguments);
        $managerOptions = ['capabilities' => [FirefoxOptions::CAPABILITY => $firefoxOptions]];

        return Client::createFirefoxClient(null, $arguments, $managerOptions, $baseUri);
    }

    public function createChrome(string $baseUri, BrowserOptions $options): Client
    {
        if (null === $options->acceptLanguage) {
            return Client::createChromeClient(null, null, [], $baseUri);
        }

        $chromeOptions = $this->optionsNormalizer->forChrome($options);
        $managerOptions = ['capabilities' => [ChromeOptions::CAPABILITY => $chromeOptions]];

        return Client::createChromeClient(null, null, $managerOptions, $baseUri);
    }

    /**
     * @return list<string>
     */
    private function firefoxArguments(): array
    {
        $arguments = [];

        if (!filter_var($_SERVER['PANTHER_NO_HEADLESS'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $arguments[] = '--headless';
        }

        if (filter_var($_SERVER['PANTHER_DEVTOOLS'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $arguments[] = '--devtools';
        }

        if ($_SERVER['PANTHER_FIREFOX_ARGUMENTS'] ?? false) {
            array_push($arguments, ...explode(' ', (string) $_SERVER['PANTHER_FIREFOX_ARGUMENTS']));
        }

        return $arguments;
    }
}
