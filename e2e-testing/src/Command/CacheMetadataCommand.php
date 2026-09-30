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

use Contao\E2eTesting\Cache\CacheMetadataFactory;
use Contao\E2eTesting\Exception\E2eTestException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('cache:metadata', 'Print cache fingerprints and paths for CI systems')]
final class CacheMetadataCommand extends AbstractWorkspaceCommand
{
    public function __construct(
        private readonly CacheMetadataFactory $metadataFactory = new CacheMetadataFactory(),
        private readonly GithubOutputWriter $githubOutputWriter = new GithubOutputWriter(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('github-output', null, InputOption::VALUE_NONE, 'Also write values to the file named by GITHUB_OUTPUT');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $metadata = $this->metadataFactory->create($this->cache());
        $output->writeln(json_encode(
            $metadata,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        if ($input->getOption('github-output')) {
            $path = getenv('GITHUB_OUTPUT');

            if (false === $path || '' === $path) {
                throw new E2eTestException('The GITHUB_OUTPUT environment variable is required with --github-output.');
            }

            $this->githubOutputWriter->write($path, [
                'playwright_fingerprint' => $metadata['playwright']['fingerprint'],
                'playwright_path' => $metadata['playwright']['path'],
                'managed_edition_fingerprint' => $metadata['managed_edition']['fingerprint'],
                'managed_edition_paths' => implode("\n", array_values($metadata['managed_edition']['paths'])),
            ]);
        }

        return self::SUCCESS;
    }
}
