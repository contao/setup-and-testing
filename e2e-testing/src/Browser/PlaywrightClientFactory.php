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

use Playwright\Configuration\PlaywrightConfig;
use Playwright\Configuration\PlaywrightConfigBuilder;
use Playwright\PlaywrightClient;
use Playwright\PlaywrightFactory;

final class PlaywrightClientFactory implements PlaywrightClientFactoryInterface
{
    public function create(PlaywrightConfig $config): PlaywrightClient
    {
        // Never go below 30 seconds for the transport, so a low PW_TIMEOUT_MS does not
        // break launching the browser
        return PlaywrightFactory::create(
            PlaywrightConfigBuilder::fromEnv()->withTimeoutMs(max($config->timeoutMs, 30_000))->build(),
        );
    }
}
