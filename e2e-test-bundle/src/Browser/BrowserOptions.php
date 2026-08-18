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

final readonly class BrowserOptions
{
    private function __construct(public string|null $acceptLanguage = null)
    {
    }

    public static function create(): self
    {
        return new self();
    }

    public function withAcceptLanguage(string $acceptLanguage): self
    {
        $acceptLanguage = trim($acceptLanguage);

        if ('' === $acceptLanguage) {
            throw new \InvalidArgumentException('The accepted browser language must not be empty.');
        }

        return new self($acceptLanguage);
    }

    public function sessionKey(): string
    {
        return $this->acceptLanguage ?? '';
    }
}
