<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Process;

interface ProcessRunnerInterface
{
    /**
     * @param list<string>          $command
     * @param array<string, string> $environment
     */
    public function run(array $command, string $directory, array $environment = []): string;
}
