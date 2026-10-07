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

use Contao\E2eTesting\Http\PhpServerConfig;
use Contao\E2eTesting\Http\WebServerConfig;
use Contao\E2eTesting\Http\WebServerManager;

final class LocalApplicationConfig implements ApplicationConfigInterface
{
    private function __construct(
        private WebServerConfig $server,
        private string $traceDirectory = '.contao-e2e/traces',
    ) {
    }

    public static function php(string $directory, string $documentRoot = 'public', string|null $router = null): self
    {
        return new self(WebServerConfig::php($directory, $documentRoot, $router));
    }

    /**
     * @param list<string> $command
     */
    public static function command(array $command, string $directory): self
    {
        return new self(WebServerConfig::command($command, $directory));
    }

    /**
     * @param array<string, string|false> $environment
     */
    public function withEnvironment(array $environment): self
    {
        $clone = clone $this;
        $clone->server = $this->server->withEnvironment($environment);

        return $clone;
    }

    public function withPhpServer(PhpServerConfig $phpServer): self
    {
        $clone = clone $this;
        $clone->server = $this->server->withPhpServer($phpServer);

        return $clone;
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

    public function createApplication(ApplicationRuntime $runtime): Application
    {
        $server = (new WebServerManager())->start($this->server);

        try {
            $config = ApplicationConfig::create($server->baseUri)->withTraceDirectory($this->traceDirectory);

            return new Application(
                $config,
                $runtime->createBrowserRuntime($this->traceDirectory),
                $runtime,
                $server,
            );
        } catch (\Throwable $exception) {
            $server->stop();

            throw $exception;
        }
    }
}
