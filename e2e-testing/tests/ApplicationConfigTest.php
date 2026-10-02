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

use Contao\E2eTesting\Application\ApplicationConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApplicationConfigTest extends TestCase
{
    public function testConfigurationKeepsTheBasePathAndReturnsClones(): void
    {
        $original = ApplicationConfig::create('https://example.test/app/');
        $configured = $original->withTraceDirectory('/tmp/project-traces');

        $this->assertNotSame($original, $configured);
        $this->assertSame('https://example.test/app', $configured->baseUri);
        $this->assertSame('.contao-e2e/traces', $original->traceDirectory());
        $this->assertSame('/tmp/project-traces', $configured->traceDirectory());
    }

    #[DataProvider('invalidUris')]
    public function testRejectsInvalidBaseUris(string $uri): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApplicationConfig::create($uri);
    }

    public static function invalidUris(): iterable
    {
        yield [''];
        yield ['/relative'];
        yield ['ftp://example.test'];
        yield ['https://'];
        yield ['https://example.test/?query=1'];
        yield ['https://example.test/#fragment'];
    }

    public function testRejectsAnEmptyTraceDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApplicationConfig::create('http://localhost:8080')->withTraceDirectory(' ');
    }
}
