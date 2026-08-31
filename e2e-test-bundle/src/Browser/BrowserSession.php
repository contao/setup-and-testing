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

use Playwright\Browser\BrowserContextInterface;
use Playwright\Exception\PlaywrightExceptionInterface;
use Playwright\Network\ResponseInterface;
use Playwright\Page\PageInterface;

final class BrowserSession
{
    private bool $closed = false;

    public function __construct(
        private readonly string $baseUri,
        private readonly BrowserContextInterface $context,
        private readonly PageInterface $page,
    ) {
    }

    public function context(): BrowserContextInterface
    {
        return $this->context;
    }

    public function page(): PageInterface
    {
        return $this->page;
    }

    public function visit(string $path = '/'): ResponseInterface|null
    {
        return $this->page->goto($this->uri($path));
    }

    public function uri(string $path = '/'): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return rtrim($this->baseUri, '/').'/'.ltrim($path, '/');
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        try {
            $this->context->close();
        } catch (PlaywrightExceptionInterface) {
            // The browser or Playwright process has already stopped.
        }
    }
}
