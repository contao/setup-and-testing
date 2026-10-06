<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Tests;

use Contao\E2eTesting\Http\ApplicationHttpBrowser;
use Contao\E2eTesting\Http\SimulatedOrigin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ApplicationHttpBrowserTest extends TestCase
{
    #[DataProvider('initialUrls')]
    public function testInitialUrlUsesTheApplicationOrigin(string $path, string $expected): void
    {
        $browser = new ApplicationHttpBrowser('https://example.test:8443/', new MockHttpClient(new MockResponse('OK')));
        $browser->request('GET', $path);
        $this->assertSame($expected, $browser->getInternalRequest()->getUri());
    }

    public static function initialUrls(): iterable
    {
        yield 'root' => ['/', 'https://example.test:8443/'];
        yield 'double slash' => ['//', 'https://example.test:8443//'];
        yield 'double slash query' => ['//?test=1', 'https://example.test:8443//?test=1'];
        yield 'triple slash path' => ['///endpoint', 'https://example.test:8443///endpoint'];
        yield 'relative path' => ['endpoint', 'https://example.test:8443/endpoint'];
        yield 'root path' => ['/endpoint', 'https://example.test:8443/endpoint'];
        yield 'query' => ['?test=1', 'https://example.test:8443/?test=1'];
        yield 'absolute http' => ['http://other.test/endpoint', 'http://other.test/endpoint'];
        yield 'protocol relative' => ['//other.test/endpoint', 'https://other.test/endpoint'];
    }

    public function testRelativeUrlsUseTheCurrentOriginAfterAnAbsoluteRequest(): void
    {
        $client = new MockHttpClient([new MockResponse('OK'), new MockResponse('OK')]);
        $browser = new ApplicationHttpBrowser('https://example.test:8443/', $client);
        $browser->request('GET', 'http://other.test/directory/endpoint');
        $browser->request('GET', 'next');
        $this->assertSame('http://other.test/directory/next', $browser->getInternalRequest()->getUri());
    }

    public function testRestartRestoresTheApplicationOrigin(): void
    {
        $client = new MockHttpClient([new MockResponse('OK'), new MockResponse('OK')]);
        $browser = new ApplicationHttpBrowser('https://example.test:8443/', $client);
        $browser->request('GET', 'http://other.test/');
        $browser->restart();
        $browser->request('GET', '/endpoint');
        $this->assertSame('https://example.test:8443/endpoint', $browser->getInternalRequest()->getUri());
    }

    #[DataProvider('redirectUrls')]
    public function testSimulatedOriginsResolveProtocolRelativeRedirects(string $location, string $expected): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: '.$location]]),
            new MockResponse('OK'),
        ]);
        $browser = new ApplicationHttpBrowser('http://localhost:8080/', $client, SimulatedOrigin::fromUri('https://example.local'));
        $browser->followRedirects(false);
        $browser->request('GET', '/redirect');
        $this->assertSame($location, $browser->getInternalResponse()->getHeader('Location'));
        $browser->followRedirect();
        $this->assertSame($expected, $browser->getInternalRequest()->getUri());
    }

    public static function redirectUrls(): iterable
    {
        yield 'selected origin' => ['//example.local//path?test=1', 'http://localhost:8080//path?test=1'];
        yield 'external origin' => ['//other.local/path', 'https://other.local/path'];
        yield 'empty authority' => ['//?test=1', 'http://localhost:8080//?test=1'];
    }
}
