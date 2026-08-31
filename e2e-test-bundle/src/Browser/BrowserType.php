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
    case Chromium;
    case Firefox;
    case WebKit;

    public function value(): string
    {
        return match ($this) {
            self::Chromium => 'chromium',
            self::Firefox => 'firefox',
            self::WebKit => 'webkit',
        };
    }
}
