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
use Contao\E2eTesting\Cache\WorkspaceInitializer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

#[AsCommand('cache:metadata', 'Write cache keys and print metadata for CI systems')]
final class CacheMetadataCommand extends AbstractWorkspaceCommand
{
    public function __construct(
        private readonly CacheMetadataFactory $metadataFactory = new CacheMetadataFactory(),
        private readonly WorkspaceInitializer $workspaceInitializer = new WorkspaceInitializer(),
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->cache();
        $this->workspaceInitializer->initialize($config);
        $metadata = $this->metadataFactory->create($config);
        $this->filesystem->dumpFile(
            Path::join($config->cacheKeysDirectory(), 'playwright'),
            $metadata['playwright']['fingerprint']."\n",
        );
        $this->filesystem->dumpFile(
            Path::join($config->cacheKeysDirectory(), 'managed-edition'),
            $metadata['managed_edition']['fingerprint']."\n",
        );
        $output->writeln(json_encode(
            $metadata,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
