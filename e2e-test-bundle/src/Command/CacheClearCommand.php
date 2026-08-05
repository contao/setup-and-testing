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

use Contao\E2eTestBundle\Cache\WorkspaceCleaner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('cache:clear', 'Clear reusable dependency and Managed Edition caches')]
final class CacheClearCommand extends AbstractWorkspaceCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        (new WorkspaceCleaner())->clearCache($this->cache());
        (new SymfonyStyle($input, $output))->success('E2E dependency and installation caches cleared.');

        return self::SUCCESS;
    }
}
