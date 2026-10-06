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

use Contao\E2eTesting\Http\HttpRequest;
use Contao\E2eTesting\Http\SimulatedOrigin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

final class SimulatedOriginValueTest extends TestCase
{
    public function testOriginHeadersReplacePreviousValuesWithoutChangingTheRequest(): void
    {
        $original = HttpRequest::json('POST', '//api')->withJson(['example' => true]);
        $first = $original->withSimulatedOrigin('https://EXAMPLE.local:8443/');
        $request = $first->withSimulatedOrigin('http://other.local');
        $this->assertSame(['Accept' => 'application/json', 'Content-Type' => 'application/json'], $original->headers);
        $this->assertSame('other.local', $request->headers['X-Forwarded-Host']);
        $this->assertSame('http', $request->headers['X-Forwarded-Proto']);
        $this->assertSame('80', $request->headers['X-Forwarded-Port']);
        $this->assertSame('//api', $request->path);
        $this->assertSame($original->body, $request->body);
        $this->assertSame('example.local', $first->headers['X-Forwarded-Host']);
        $this->assertSame('8443', $first->headers['X-Forwarded-Port']);
    }

    public function testNonLoopbackConnectionsCannotSupplyThePublicOrigin(): void
    {
        $framework = Yaml::parseFile(\dirname(__DIR__).'/config/simulated-origin.yaml')['framework'];
        $previous = Request::getTrustedProxies();
        $previousHeaders = Request::getTrustedHeaderSet();

        try {
            Request::setTrustedProxies($framework['trusted_proxies'], Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);
            $request = Request::create('http://localhost/');
            $request->server->set('REMOTE_ADDR', '203.0.113.10');

            foreach (SimulatedOrigin::fromUri('https://example.local')->headers() as $name => $value) {
                $request->headers->set($name, $value);
            }
            $this->assertSame('http', $request->getScheme());
            $this->assertSame('localhost', $request->getHost());
            $this->assertSame(80, $request->getPort());
        } finally {
            Request::setTrustedProxies($previous, $previousHeaders);
        }
    }

    #[DataProvider('invalidOrigins')]
    public function testInvalidOriginsAreRejected(string $uri): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SimulatedOrigin::fromUri($uri);
    }

    public static function invalidOrigins(): iterable
    {
        yield 'missing scheme' => ['example.local'];
        yield 'other scheme' => ['ftp://example.local'];
        yield 'credentials' => ['https://user@example.local'];
        yield 'path' => ['https://example.local/path'];
        yield 'query' => ['https://example.local?query=1'];
        yield 'fragment' => ['https://example.local#fragment'];
        yield 'zero port' => ['https://example.local:0'];
        yield 'invalid host' => ['https://bad_host.local'];
        yield 'header injection' => ["https://example.local\r\nX-Injected: yes"];
    }
}
