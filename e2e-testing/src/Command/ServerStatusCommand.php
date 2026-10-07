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

use Contao\E2eTesting\Inspection\InspectionDetails;
use Contao\E2eTesting\Inspection\InspectionSessionStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('server:status', 'Show the background inspection session status')]
final class ServerStatusCommand extends AbstractWorkspaceCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = InspectionSessionStore::forCache($this->cache());
        $state = $store->read();
        $active = $store->isActive();
        $phase = (string) ($state['phase'] ?? 'stopped');

        if (!$active && \in_array($phase, ['starting', 'running'], true)) {
            $phase = 'stale';
        }

        $io = new SymfonyStyle($input, $output);
        $io->definitionList(['Status' => $phase], ['Active' => $active ? 'yes' : 'no'], ['Log' => $store->logFile()]);

        if ($active && 'running' === $phase) {
            $io->definitionList(...InspectionDetails::fromState($state)->rows());
        }

        return 'failed' === $phase || 'stale' === $phase ? self::FAILURE : self::SUCCESS;
    }
}
