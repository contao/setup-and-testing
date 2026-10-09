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
 * @phpstan-type Cookie array{name: string, value: string, domain: string, path: string, expires: int, httpOnly: bool, secure: bool, sameSite: 'Strict'|'Lax'|'None'}
 */
final readonly class SessionSnapshot
{
    /**
     * @param list<Cookie>          $cookies
     * @param array<string, string> $files
     */
    public function __construct(
        public array $cookies,
        public array $files = [],
    ) {
    }
}
