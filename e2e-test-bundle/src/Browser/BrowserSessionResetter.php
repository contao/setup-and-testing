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

use Facebook\WebDriver\Exception\WebDriverException;
use Symfony\Component\Panther\Client;

final readonly class BrowserSessionResetter
{
    public function reset(Client $client): bool
    {
        if (!$client->ping()) {
            return false;
        }

        try {
            $this->closeAdditionalWindows($client);
            $client->executeScript('window.localStorage.clear(); window.sessionStorage.clear();');
            $client->manage()->deleteAllCookies();
            $client->get('about:blank');

            return true;
        } catch (WebDriverException) {
            return false;
        }
    }

    private function closeAdditionalWindows(Client $client): void
    {
        $handles = $client->getWindowHandles();
        $primaryHandle = array_shift($handles);

        foreach ($handles as $handle) {
            $client->switchTo()->window($handle);
            $client->close();
        }

        if (null !== $primaryHandle) {
            $client->switchTo()->window($primaryHandle);
        }
    }
}
