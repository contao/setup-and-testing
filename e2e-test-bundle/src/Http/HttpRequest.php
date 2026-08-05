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

final readonly class HttpRequest
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public string $path,
        public Origin $origin,
        public array $headers = [],
    ) {
    }

    public static function get(string $path, Origin $origin): self
    {
        return new self($path, $origin);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->path, $this->origin, [...$this->headers, $name => $value]);
    }
}
