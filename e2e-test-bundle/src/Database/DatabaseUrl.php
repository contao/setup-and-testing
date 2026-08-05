<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Database;

use Contao\E2eTestBundle\Exception\E2eTestException;

final readonly class DatabaseUrl
{
    /**
     * @param array<string, int|string> $parts
     */
    private function __construct(private array $parts)
    {
    }

    public static function parse(string $url): self
    {
        $parts = parse_url($url);

        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            throw new E2eTestException('The E2E database URL is invalid.');
        }

        return new self($parts);
    }

    public function withDatabase(string $database): string
    {
        $authentication = '';

        if (isset($this->parts['user'])) {
            $authentication = $this->parts['user'];

            if (isset($this->parts['pass'])) {
                $authentication .= ':'.$this->parts['pass'];
            }

            $authentication .= '@';
        }

        $port = isset($this->parts['port']) ? ':'.$this->parts['port'] : '';
        $query = isset($this->parts['query']) ? '?'.$this->parts['query'] : '';

        return \sprintf('%s://%s%s%s/%s%s', $this->parts['scheme'], $authentication, $this->parts['host'], $port, rawurlencode($database), $query);
    }
}
