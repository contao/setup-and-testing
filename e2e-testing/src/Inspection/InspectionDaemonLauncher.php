<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Inspection;

use Symfony\Component\Process\Process;

final readonly class InspectionDaemonLauncher
{
    /**
     * @param list<string> $command
     */
    public function launch(array $command, InspectionSessionStore $store): void
    {
        if ('Windows' === PHP_OS_FAMILY) {
            $this->launchWindows($command, $store);

            return;
        }

        $shell = 'nohup '.implode(' ', array_map(escapeshellarg(...), $command));
        $shell .= ' >'.escapeshellarg($store->logFile()).' 2>&1 < /dev/null &';
        Process::fromShellCommandline($shell)->mustRun();
    }

    /**
     * @param list<string> $command
     */
    private function launchWindows(array $command, InspectionSessionStore $store): void
    {
        $executable = array_shift($command);
        $arguments = implode(' ', array_map($this->quoteWindowsArgument(...), $command));
        $script = '$ErrorActionPreference = \'Stop\'; Start-Process -WindowStyle Hidden';
        $script .= ' -FilePath '.$this->quotePowerShell($executable);
        $script .= ' -ArgumentList '.$this->quotePowerShell($arguments);
        $script .= ' -WorkingDirectory '.$this->quotePowerShell((string) getcwd());
        $script .= ' -RedirectStandardOutput '.$this->quotePowerShell($store->logFile());
        $script .= ' -RedirectStandardError '.$this->quotePowerShell($store->logFile().'.error');
        (new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', $script]))->mustRun();
    }

    private function quotePowerShell(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private function quoteWindowsArgument(string $value): string
    {
        $value = preg_replace('/(\\\\*)"/', '$1$1\\\\"', $value);
        $value = preg_replace('/(\\\\+)$/', '$1$1', $value);

        return '"'.$value.'"';
    }
}
