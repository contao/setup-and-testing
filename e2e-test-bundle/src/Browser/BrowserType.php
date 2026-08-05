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

enum BrowserType
{
    case Firefox;
    case Chrome;

    public function driverFilename(): string
    {
        $filename = match ($this) {
            self::Firefox => 'geckodriver',
            self::Chrome => 'chromedriver',
        };

        return 'Windows' === PHP_OS_FAMILY ? $filename.'.exe' : $filename;
    }

    public function installCommand(string|null $browserPath): string
    {
        return match ($this) {
            self::Firefox => 'browser:firefox',
            self::Chrome => $browserPath && str_contains(strtolower($browserPath), 'chromium')
                ? 'browser:chromium'
                : 'browser:google-chrome',
        };
    }

    public function browserBinary(): string|null
    {
        $variable = match ($this) {
            self::Firefox => 'PANTHER_FIREFOX_BINARY',
            self::Chrome => 'PANTHER_CHROME_BINARY',
        };
        $path = $_SERVER[$variable] ?? null;

        return \is_string($path) && '' !== $path ? $path : null;
    }

    public function installerBrowserPath(): string|null
    {
        $path = $this->browserBinary();

        if ('Darwin' === PHP_OS_FAMILY && null !== $path && preg_match('#^(.+?\.app)(?:/|$)#', $path, $match)) {
            return $match[1];
        }

        return $path;
    }
}
