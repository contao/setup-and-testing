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

use Contao\E2eTesting\Database\DockerDatabaseServer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('database:stop', 'Stop all Docker database variants for this project')]
final class DatabaseStopCommand extends AbstractWorkspaceCommand
{
    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Stop databases that are still used by E2E tests');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        (new DockerDatabaseServer())->stop($this->cache(), (bool) $input->getOption('force'));
        (new SymfonyStyle($input, $output))->success('E2E Docker databases stopped.');

        return self::SUCCESS;
    }
}
