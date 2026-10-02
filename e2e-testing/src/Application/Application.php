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

use Contao\E2eTesting\Browser\BackendBrowser;
use Contao\E2eTesting\Browser\BrowserOptions;
use Contao\E2eTesting\Browser\BrowserRuntime;
use Contao\E2eTesting\Browser\BrowserSession;
use Contao\E2eTesting\Browser\BrowserType;
use Contao\E2eTesting\Http\WebServerProcess;

final class Application implements ApplicationInterface
{
    private readonly BrowserRuntime $browserRuntime;

    public function __construct(
        private readonly ApplicationConfig $config,
        BrowserRuntime|null $browserRuntime = null,
        private readonly WebServerProcess|null $server = null,
    ) {
        $this->browserRuntime = $browserRuntime ?? new BrowserRuntime($config->traceDirectory());
    }

    public function createBrowser(BrowserType $type = BrowserType::Firefox, BrowserOptions|null $options = null): BrowserSession
    {
        return $this->browserRuntime->createBrowser($this->config->baseUri, $type, $options);
    }

    public function createBackendBrowser(BrowserType $type = BrowserType::Firefox, BrowserOptions|null $options = null): BackendBrowser
    {
        return new BackendBrowser($this->createBrowser($type, $options));
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
