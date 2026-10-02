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

interface ApplicationInterface
{
    public function createBrowser(BrowserType $type = BrowserType::Firefox, BrowserOptions|null $options = null): BrowserSession;

    public function createBackendBrowser(BrowserType $type = BrowserType::Firefox, BrowserOptions|null $options = null): BackendBrowser;

    public function browserRuntime(): BrowserRuntime;

    public function resetState(): void;

    public function release(): void;
}
