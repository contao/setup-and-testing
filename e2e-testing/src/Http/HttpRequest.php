<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Http;

final readonly class HttpRequest
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public string $method,
        public string $path,
        public Origin|null $origin,
        public array $headers = [],
        public string|null $body = null,
    ) {
    }

    public static function create(string $method, string $path, Origin|null $origin = null): self
    {
        return new self(strtoupper($method), $path, $origin);
    }

    public static function get(string $path, Origin|null $origin = null): self
    {
        return self::create('GET', $path, $origin);
    }

    public static function json(string $method, string $path, Origin|null $origin = null): self
    {
        return self::create($method, $path, $origin)->withHeader('Accept', 'application/json');
    }

    public function withHeader(string $name, string $value): self
    {
        return $this->withHeaders([$name => $value]);
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $merged = $this->headers;

        foreach ($headers as $name => $value) {
            foreach (array_keys($merged) as $existing) {
                if (0 === strcasecmp($name, $existing)) {
                    unset($merged[$existing]);
                }
            }

            $merged[$name] = $value;
        }

        return new self($this->method, $this->path, $this->origin, $merged, $this->body);
    }

    public function withBody(string|null $body): self
    {
        return new self($this->method, $this->path, $this->origin, $this->headers, $body);
    }

    public function withJson(mixed $body): self
    {
        $request = $this->withBody(json_encode($body, JSON_THROW_ON_ERROR));

        foreach (['Accept', 'Content-Type'] as $name) {
            if (!$request->hasHeader($name)) {
                $request = $request->withHeader($name, 'application/json');
            }
        }

        return $request;
    }

    private function hasHeader(string $name): bool
    {
        foreach (array_keys($this->headers) as $existing) {
            if (0 === strcasecmp($name, $existing)) {
                return true;
            }
        }

        return false;
    }
}
