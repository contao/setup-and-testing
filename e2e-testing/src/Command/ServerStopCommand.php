<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Command;

use Contao\E2eTesting\Inspection\InspectionDaemonManager;
use Contao\E2eTesting\Inspection\InspectionSessionStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('server:stop', 'Stop the background inspection session')]
final class ServerStopCommand extends AbstractWorkspaceCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stopped = (new InspectionDaemonManager())->stop(InspectionSessionStore::forCache($this->cache()));
        (new SymfonyStyle($input, $output))->success($stopped ? 'Inspection session stopped.' : 'No background inspection session is running.');

        return self::SUCCESS;
    }
}
