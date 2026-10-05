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
use Contao\E2eTesting\Http\HttpRequest;
use Contao\E2eTesting\Http\Origin;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Contracts\HttpClient\ResponseInterface;

interface ApplicationInterface
{
    public function createBrowser(BrowserType $type = BrowserType::Firefox, BrowserOptions|null $options = null, Origin|null $origin = null): BrowserSession;

    public function createHttpBrowser(Origin|null $origin = null): HttpBrowser;

    public function uri(string $path = '/', Origin|null $origin = null): string;

    public function send(HttpRequest $request): ResponseInterface;

    public function browserRuntime(): BrowserRuntime;

    public function resetState(): void;

    public function release(): void;
}
