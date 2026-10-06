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

use Symfony\Component\Process\Process;

final class ServerProcess
{
    private readonly WebServerProcess $process;

    public function __construct(
        Process|WebServerProcess $process,
        public readonly int $port,
    ) {
        $this->process = $process instanceof WebServerProcess ? $process : new WebServerProcess($process, $port);
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function stop(): void
    {
        $this->process->stop();
    }
}
