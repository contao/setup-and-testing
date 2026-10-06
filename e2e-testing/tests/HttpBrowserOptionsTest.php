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

use Contao\E2eTesting\Http\HttpBrowserOptions;
use PHPUnit\Framework\TestCase;

final class HttpBrowserOptionsTest extends TestCase
{
    public function testConfiguresAndReplacesOriginsWithoutMutatingExistingOptions(): void
    {
        $original = HttpBrowserOptions::create();
        $configured = $original->withSimulatedOrigin('https://example.local:8443');
        $replacement = $configured->withSimulatedOrigin('http://other.local');

        $this->assertNull($original->simulatedOrigin());
        $this->assertSame('https', $configured->simulatedOrigin()?->scheme);
        $this->assertSame('example.local', $configured->simulatedOrigin()?->host);
        $this->assertSame(8443, $configured->simulatedOrigin()?->port);
        $this->assertSame('http', $replacement->simulatedOrigin()?->scheme);
        $this->assertSame('other.local', $replacement->simulatedOrigin()?->host);
        $this->assertSame(80, $replacement->simulatedOrigin()?->port);
    }

    public function testRejectsInvalidOriginsWhenConfiguringOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HttpBrowserOptions::create()->withSimulatedOrigin('https://example.local/path');
    }
}
