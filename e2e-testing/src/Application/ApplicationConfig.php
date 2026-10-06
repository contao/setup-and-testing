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

use Contao\E2eTesting\Browser\BrowserRuntime;
use Contao\E2eTesting\Browser\PlaywrightManager;

final class ApplicationConfig implements ApplicationConfigInterface
{
    private function __construct(
        public readonly string $baseUri,
        private string $traceDirectory = '.contao-e2e/traces',
    ) {
    }

    public static function create(string $baseUri): self
    {
        $parts = parse_url($baseUri);

        if (false === $parts || !\in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host']) || (isset($parts['query']) || isset($parts['fragment']))) {
            throw new \InvalidArgumentException('The application base URI must be an absolute HTTP or HTTPS URL without a query or fragment.');
        }

        return new self(rtrim($baseUri, '/'));
    }

    public function createApplication(ApplicationRuntime $runtime): Application
    {
        return new Application(
            $this,
            new BrowserRuntime($this->traceDirectory, new PlaywrightManager()),
            $runtime,
        );
    }

    public function traceDirectory(): string
    {
        return $this->traceDirectory;
    }

    public function withTraceDirectory(string $traceDirectory): self
    {
        if ('' === trim($traceDirectory)) {
            throw new \InvalidArgumentException('The trace directory must not be empty.');
        }

        $clone = clone $this;
        $clone->traceDirectory = $traceDirectory;

        return $clone;
    }
}
