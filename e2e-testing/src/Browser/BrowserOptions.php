<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Browser;

final class BrowserOptions
{
    private string|null $acceptLanguage = null;

    private int|null $viewportWidth = null;

    private int|null $viewportHeight = null;

    private function __construct()
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

        $clone = clone $this;
        $clone->acceptLanguage = $acceptLanguage;

        return $clone;
    }

    public function withViewport(int $width, int $height): self
    {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('The browser viewport dimensions must be positive integers.');
        }

        $clone = clone $this;
        $clone->viewportWidth = $width;
        $clone->viewportHeight = $height;

        return $clone;
    }

    public function acceptLanguage(): string|null
    {
        return $this->acceptLanguage;
    }

    public function viewportWidth(): int|null
    {
        return $this->viewportWidth;
    }

    public function viewportHeight(): int|null
    {
        return $this->viewportHeight;
    }
}
