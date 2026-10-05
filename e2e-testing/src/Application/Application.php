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
use Contao\E2eTesting\Exception\E2eTestException;
use Contao\E2eTesting\Http\Origin;
use Contao\E2eTesting\Http\WebServerProcess;

final class Application implements ApplicationInterface
{
    use HttpApplicationTrait;

    private readonly BrowserRuntime $browserRuntime;

    public function __construct(
        private readonly ApplicationConfig $config,
        BrowserRuntime|null $browserRuntime = null,
        private readonly WebServerProcess|null $server = null,
    ) {
        $this->browserRuntime = $browserRuntime ?? new BrowserRuntime($config->traceDirectory());
    }

    public function createBrowser(BrowserType $type = BrowserType::Firefox, BrowserOptions|null $options = null, Origin|null $origin = null): BrowserSession
    {
        return $this->browserRuntime->createBrowser(rtrim($this->uri(origin: $origin), '/'), $type, $options);
    }

    public function uri(string $path = '/', Origin|null $origin = null): string
    {
        $baseUri = $this->config->baseUri;

        if ($origin) {
            $server = $this->server ?? throw new E2eTestException('Origin emulation requires a locally managed PHP server with the generated router.');
            $baseUri = $server->originUri($origin).parse_url($baseUri, PHP_URL_PATH);
        }

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
