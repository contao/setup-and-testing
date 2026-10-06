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

use Contao\E2eTesting\Http\ApplicationHttpBrowser;
use Contao\E2eTesting\Http\HttpRequest;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

trait HttpApplicationTrait
{
    abstract public function uri(string $path = '/'): string;

    public function send(HttpRequest $request): ResponseInterface
    {
        return HttpClient::create(['max_redirects' => 0])->request(
            $request->method,
            $this->uri($request->path),
            ['headers' => $request->headers, 'body' => $request->body],
        );
    }

    public function createHttpBrowser(): HttpBrowser
    {
        $uri = $this->uri();
        $host = parse_url((string) $uri, PHP_URL_HOST);
        $port = parse_url((string) $uri, PHP_URL_PORT);

        if (!\is_string($host)) {
            throw new \LogicException('Could not determine the application web server host.');
        }

        $host .= \is_int($port) ? ':'.$port : '';
        $baseUri = parse_url((string) $uri, PHP_URL_SCHEME).'://'.$host.'/';
        $browser = new ApplicationHttpBrowser($baseUri, $this->createHttpBrowserClient($uri, $host));
        $browser->followRedirects(false);

        return $browser;
    }

    private function createHttpBrowserClient(string $uri, string $host): HttpClientInterface
    {
        $user = parse_url($uri, PHP_URL_USER);

        if (!\is_string($user)) {
            return HttpClient::create(['max_redirects' => 0]);
        }

        return HttpClient::createForBaseUri(
            parse_url($uri, PHP_URL_SCHEME).'://'.$host.'/',
            [
                'max_redirects' => 0,
                'auth_basic' => [rawurldecode($user), rawurldecode((string) parse_url($uri, PHP_URL_PASS))],
            ],
        );
    }
}
