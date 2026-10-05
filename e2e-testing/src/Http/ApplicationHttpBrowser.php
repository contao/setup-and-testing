<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Http;

use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\DomCrawler\UriResolver;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ApplicationHttpBrowser extends HttpBrowser
{
    public function __construct(
        private readonly string $baseUri,
        HttpClientInterface $client,
    ) {
        parent::__construct($client);
    }

    protected function getAbsoluteUri(string $uri): string
    {
        if (!$this->getHistory()->isEmpty()) {
            return parent::getAbsoluteUri($uri);
        }

        return UriResolver::resolve($uri, $this->baseUri);
    }
}
