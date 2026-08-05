<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Command;

use Contao\E2eTestBundle\Cache\WorkspaceInitializer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('doctor', 'Initialize and verify the project-local E2E workspace')]
final class DoctorCommand extends AbstractWorkspaceCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->cache();
        (new WorkspaceInitializer())->initialize($config);
        (new SymfonyStyle($input, $output))->success('E2E workspace ready at '.$config->rootDirectory);

        return self::SUCCESS;
    }
}
