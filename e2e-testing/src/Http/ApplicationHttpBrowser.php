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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ApplicationHttpBrowser extends HttpBrowser
{
    public function __construct(
        private readonly string $baseUri,
        HttpClientInterface $client,
        private readonly SimulatedOrigin|null $simulatedOrigin = null,
    ) {
        parent::__construct($client);
    }

    public function followRedirect(): Crawler
    {
        if ($this->simulatedOrigin && isset($this->redirect) && preg_match('{^//[^/#?]}', $this->redirect)) {
            $this->redirect = $this->simulatedOrigin->scheme.':'.$this->redirect;
        }

        return parent::followRedirect();
    }

    protected function getAbsoluteUri(string $uri): string
    {
        if ($this->simulatedOrigin?->matchesUri($uri)) {
            return $this->simulatedOrigin->localUri($this->baseUri, $uri);
        }

        if (preg_match('{^//(?:[/#?]|$)}', $uri) || ($this->simulatedOrigin && str_starts_with($uri, '//'))) {
            $baseUri = $this->getHistory()->isEmpty() ? $this->baseUri : $this->getHistory()->current()->getUri();
            $parts = parse_url($baseUri);
            $port = isset($parts['port']) ? ':'.$parts['port'] : '';

            return $parts['scheme'].'://'.$parts['host'].$port.$uri;
        }

        if (!$this->getHistory()->isEmpty()) {
            return parent::getAbsoluteUri($uri);
        }

        return UriResolver::resolve($uri, $this->baseUri);
    }
}
