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

use Contao\E2eTesting\Exception\E2eTestException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final readonly class WebServerManager
{
    public function __construct(
        private FreePortFinder $portFinder = new FreePortFinder(),
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function start(WebServerConfig $config): WebServerProcess
    {
        $port = $this->portFinder->find();
        $command = $config->commandForPort($port);
        $origins = null !== $config->frontController() && is_file($config->frontController()) ? new OriginMap($this->filesystem->tempnam(sys_get_temp_dir(), 'contao-e2e-origins-')) : null;

        try {
            $router = $this->createRouter($config, $origins);
        } catch (\Throwable $exception) {
            if ($origins) {
                $this->filesystem->remove($origins->file);
            }

            throw $exception;
        }

        if (null !== $router) {
            $command[] = $router;
        }

        $process = new Process($command, $config->directory, $config->environment());
        $process->setTimeout(null);

        $process->disableOutput();

        $server = new WebServerProcess($process, $port, $router, $origins);

        try {
            $process->start();
            $this->waitUntilListening($process, $port);
        } catch (\Throwable $exception) {
            $server->stop();

            throw $exception;
        }

        return $server;
    }

    private function waitUntilListening(Process $process, int $port): void
    {
        $deadline = microtime(true) + 15;

        do {
            if (!$process->isRunning()) {
                throw new E2eTestException(\sprintf('The application web server stopped during startup (exit code %s). Check your application logs for details.', $process->getExitCode() ?? 'unknown'));
            }

            $socket = @stream_socket_client('tcp://127.0.0.1:'.$port, $errorCode, $errorMessage, 0.1);

            if (false !== $socket) {
                fclose($socket);

                return;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        throw new E2eTestException('The application web server did not listen on 127.0.0.1:'.$port.' within 15 seconds.');
    }

    private function createRouter(WebServerConfig $config, OriginMap|null $origins): string|null
    {
        $index = $config->frontController();

        if (null === $index || !is_file($index)) {
            return null;
        }

        $file = $this->filesystem->tempnam(sys_get_temp_dir(), 'contao-e2e-router-');

        try {
            if ($origins) {
                $this->filesystem->dumpFile($origins->file, "{}\n");
            }
            $this->filesystem->dumpFile($file, PhpRouter::generate($index, $origins?->prelude() ?? ''));
        } catch (\Throwable $exception) {
            $this->filesystem->remove($file);

            throw $exception;
        }

        return $file;
    }
}
