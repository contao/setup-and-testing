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

final readonly class SimulatedOrigin
{
    private function __construct(
        public string $scheme,
        public string $host,
        public int $port,
    ) {
    }

    public static function fromUri(string $uri): self
    {
        $parts = parse_url($uri);
        if (
            false === $parts || !\in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || !\in_array($parts['path'] ?? '', ['', '/'], true)
            || preg_match('/[\s,\\\\]/', $uri)
        ) {
            throw new \InvalidArgumentException('A simulated origin must be an HTTP or HTTPS URL without credentials, a path, query or fragment.');
        }

        $host = strtolower($parts['host']);
        $validHost = str_starts_with($host, '[')
            ? filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            : filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
        $port = $parts['port'] ?? ('https' === $parts['scheme'] ? 443 : 80);
        if (false === $validHost || $port < 1) {
            throw new \InvalidArgumentException('A simulated origin must have a valid host and port.');
        }

        return new self($parts['scheme'], $host, $port);
    }

    public function matchesUri(string $uri): bool
    {
        $parts = parse_url($uri);
        if (false === $parts || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return ($parts['scheme'] ?? '') === $this->scheme
            && strtolower($parts['host'] ?? '') === $this->host
            && ($parts['port'] ?? ('https' === ($parts['scheme'] ?? '') ? 443 : 80)) === $this->port;
    }

    public function localUri(string $baseUri, string $uri): string
    {
        if (!$this->matchesUri($uri)) {
            return $uri;
        }

        $parts = parse_url($uri);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return rtrim($baseUri, '/').$path.$query.$fragment;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [
            'X-Forwarded-Host' => $this->host,
            'X-Forwarded-Proto' => $this->scheme,
            'X-Forwarded-Port' => (string) $this->port,
        ];
    }
}
