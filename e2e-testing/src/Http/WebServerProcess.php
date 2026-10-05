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

final class WebServerProcess
{
    public readonly string $baseUri;

    public function __construct(
        private readonly Process $process,
        public readonly int $port,
        private readonly string|null $routerFile = null,
        private readonly OriginMap|null $origins = null,
    ) {
        $this->baseUri = 'http://127.0.0.1:'.$port;
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function originUri(Origin $origin): string
    {
        $origins = $this->origins ?? throw new E2eTestException('Origin emulation requires the generated PHP router. Custom routers and server commands must provide their own origin handling.');

        return 'http://'.$origins->register($origin).':'.$this->port;
    }

    public function stop(): void
    {
        try {
            if ($this->process->isRunning()) {
                $this->process->stop(3);
            }
        } finally {
            if ($this->origins) {
                (new Filesystem())->remove($this->origins->file);
            }

            if (null !== $this->routerFile) {
                (new Filesystem())->remove($this->routerFile);
            }
        }
    }
}
