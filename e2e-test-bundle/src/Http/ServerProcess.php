<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Http;

use Symfony\Component\Process\Process;

final class ServerProcess
{
    public function __construct(
        private readonly Process $process,
        public readonly int $port,
        public readonly string $mappingFile,
    ) {
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function stop(): void
    {
        if ($this->process->isRunning()) {
            $this->process->stop(3);
        }
    }
}
