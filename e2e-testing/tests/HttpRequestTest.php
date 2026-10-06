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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpRequestTest extends TestCase
{
    public function testHeaderChangesAreImmutableAndCaseInsensitive(): void
    {
        $original = HttpRequest::create('patch', '/api/example')
            ->withHeader('Authorization', 'Bearer original')
            ->withBody('raw body')
        ;
        $request = $original->withHeaders(['authorization' => 'Bearer changed', 'Accept' => 'text/plain']);
        $this->assertSame(['Authorization' => 'Bearer original'], $original->headers);
        $this->assertSame(['authorization' => 'Bearer changed', 'Accept' => 'text/plain'], $request->headers);
        $this->assertSame('PATCH', $request->method);
        $this->assertSame('raw body', $request->body);
        $this->assertSame('/api/example', $request->path);
    }

    public function testJsonWithoutABodyOnlySetsAccept(): void
    {
        $request = HttpRequest::json('GET', '/api/example');
        $this->assertSame(['Accept' => 'application/json'], $request->headers);
        $this->assertNull($request->body);
    }

    #[DataProvider('jsonBodies')]
    public function testJsonBodiesSetTheContentType(mixed $body, string $encoded): void
    {
        $original = HttpRequest::json('POST', '/api/example');
        $request = $original->withJson($body);
        $this->assertSame($encoded, $request->body);
        $this->assertSame(['Accept' => 'application/json', 'Content-Type' => 'application/json'], $request->headers);
        $this->assertNull($original->body);
        $this->assertSame(['Accept' => 'application/json'], $original->headers);
    }

    public static function jsonBodies(): iterable
    {
        yield 'object' => [['title' => 'Example'], '{"title":"Example"}'];
        yield 'empty array' => [[], '[]'];
        yield 'null payload' => [null, 'null'];
        yield 'false payload' => [false, 'false'];
        yield 'empty string' => ['', '""'];
    }

    public function testJsonPreservesCustomMediaTypes(): void
    {
        $request = HttpRequest::create('POST', '/api/example')
            ->withHeaders(['accept' => 'application/ld+json', 'content-type' => 'application/merge-patch+json'])
            ->withJson(['title' => 'Example'])
        ;
        $this->assertSame(['accept' => 'application/ld+json', 'content-type' => 'application/merge-patch+json'], $request->headers);
        $this->assertSame('{"title":"Example"}', $request->body);
    }

    public function testJsonEncodingErrorsAreReported(): void
    {
        $this->expectException(\JsonException::class);
        HttpRequest::get('/')->withJson("\xff");
    }
}
