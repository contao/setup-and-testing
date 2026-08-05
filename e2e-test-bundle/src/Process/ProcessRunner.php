<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Process;

use Contao\E2eTestBundle\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final readonly class ProcessRunner
{
    /**
     * @param list<string>          $command
     * @param array<string, string> $environment
     */
    public function run(array $command, string $directory, array $environment = []): string
    {
        $environment['XDEBUG_MODE'] = 'off';
        $process = new Process($command, $directory, $environment + $this->environment());
        $process->setTimeout(900);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException(\sprintf("The command \"%s\" failed:\n%s%s", implode(' ', $command), $process->getOutput(), $process->getErrorOutput()));
        }

        return $process->getOutput();
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        $environment = [];

        foreach ($_SERVER as $name => $value) {
            if (\is_string($value)) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }
}
