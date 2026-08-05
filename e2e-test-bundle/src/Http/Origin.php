<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Http;

final readonly class Origin
{
    private function __construct(
        public string $host,
        public bool $https,
    ) {
    }

    public static function http(string $host): self
    {
        return new self($host, false);
    }

    public static function https(string $host): self
    {
        return new self($host, true);
    }
}
