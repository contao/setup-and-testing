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

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class WebServerProcess
{
    public readonly string $baseUri;

    public function __construct(
        private readonly Process $process,
        public readonly int $port,
        private readonly string|null $routerFile = null,
    ) {
        $this->baseUri = 'http://127.0.0.1:'.$port;
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function stop(): void
    {
        try {
            if ($this->process->isRunning()) {
                $this->process->stop(3);
            }
        } finally {
            if (null !== $this->routerFile) {
                (new Filesystem())->remove($this->routerFile);
            }
        }
    }
}
