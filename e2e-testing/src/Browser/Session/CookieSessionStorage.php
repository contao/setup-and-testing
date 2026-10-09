<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Browser\Session;

/**
 * @phpstan-import-type Cookie from SessionSnapshot
 */
final readonly class CookieSessionStorage implements SessionStorageInterface
{
    /**
     * @param list<Cookie> $cookies
     */
    public function capture(array $cookies): SessionSnapshot
    {
        return new SessionSnapshot($cookies);
    }

    /**
     * @return list<Cookie>
     */
    public function restore(SessionSnapshot $snapshot): array
    {
        return $snapshot->cookies;
    }
}
