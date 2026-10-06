<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Application;

use Contao\E2eTesting\Browser\BrowserOptions;
use Contao\E2eTesting\Browser\BrowserRuntime;
use Contao\E2eTesting\Browser\BrowserSession;
use Contao\E2eTesting\Browser\BrowserType;
use Contao\E2eTesting\Http\WebServerProcess;

final class Application implements ApplicationInterface
{
    use HttpApplicationTrait;

    public function __construct(
        private readonly ApplicationConfig $config,
        private readonly BrowserRuntime $browserRuntime,
        private readonly WebServerProcess|null $server = null,
    ) {
    }

    public function createBrowser(BrowserType $type = BrowserType::Firefox, BrowserOptions|null $options = null): BrowserSession
    {
        return $this->browserRuntime->createBrowser(rtrim($this->uri(), '/'), $type, $options);
    }

    public function uri(string $path = '/'): string
    {
        $baseUri = $this->config->baseUri;

        $path = str_starts_with($path, '/') ? $path : '/'.$path;

        return rtrim($baseUri, '/').$path;
    }

    public function browserRuntime(): BrowserRuntime
    {
        return $this->browserRuntime;
    }

    public function resetState(): void
    {
        $this->browserRuntime->reset();
    }

    public function release(): void
    {
        try {
            $this->browserRuntime->close();
        } finally {
            $this->server?->stop();
        }
    }
}
