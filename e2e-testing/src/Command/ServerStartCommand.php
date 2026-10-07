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

use Contao\E2eTesting\Application\ApplicationRuntime;
use Contao\E2eTesting\Exception\InspectionInterruptedException;
use Contao\E2eTesting\ManagedEdition\InspectionDefinitionLoader;
use Contao\E2eTesting\ManagedEdition\ManagedEdition;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('server:start', 'Prepare a Managed Edition and keep it running for manual inspection')]
final class ServerStartCommand extends AbstractWorkspaceCommand implements SignalableCommandInterface
{
    private bool $stopRequested = false;

    private bool $preparing = false;

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return \function_exists('pcntl_signal') ? [SIGINT, SIGTERM] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->stopRequested = true;

        if ($this->preparing) {
            throw new InspectionInterruptedException();
        }

        return false;
    }

    protected function configure(): void
    {
        $this->addArgument('inspection-file', InputArgument::REQUIRED, 'PHP file returning a ManagedEditionConfig or a factory callable');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->stopRequested = false;

        if (!$input->isInteractive() && !\function_exists('pcntl_signal')) {
            throw new \InvalidArgumentException('Non-interactive inspection requires PCNTL for clean shutdown. Run interactively and press Enter instead.');
        }

        $runtime = ApplicationRuntime::create();
        $edition = null;

        try {
            $this->preparing = true;

            try {
                $edition = $this->createEdition((string) $input->getArgument('inspection-file'), $runtime);
            } finally {
                $this->preparing = false;
            }

            if (!$this->isStopRequested()) {
                $this->describeEdition($edition, new SymfonyStyle($input, $output), $input->isInteractive());
                $this->waitForStop($input);
            }
        } catch (InspectionInterruptedException) {
            return self::SUCCESS;
        } finally {
            try {
                $edition?->release();
            } finally {
                $runtime->close();
            }
        }

        return self::SUCCESS;
    }

    private function createEdition(string $file, ApplicationRuntime $runtime): ManagedEdition
    {
        $definition = (new InspectionDefinitionLoader())->load($file);
        $edition = $definition instanceof \Closure ? $definition($runtime) : $definition;

        if ($edition instanceof ManagedEditionConfig) {
            $edition = $runtime->createApplication($edition);
        }

        if (!$edition instanceof ManagedEdition) {
            throw new \InvalidArgumentException('The inspection factory must return a ManagedEditionConfig or a ManagedEdition.');
        }

        if ($edition->runtime() !== $runtime) {
            $edition->release();

            throw new \InvalidArgumentException('The inspection factory must use the supplied ApplicationRuntime.');
        }

        try {
            return $edition->startServer();
        } catch (\Throwable $exception) {
            $edition->release();

            throw $exception;
        }
    }

    private function describeEdition(ManagedEdition $edition, SymfonyStyle $io, bool $interactive): void
    {
        $io->title('Managed Edition ready for inspection');
        $io->definitionList(
            ['URL' => $edition->uri()],
            ['Backend' => $edition->uri('/contao')],
            ['Directory' => $edition->directory()],
            ['Database' => $edition->database()->applicationUrl()],
        );
        $instructions = $interactive ? 'Press Enter to stop.' : 'Stop with Ctrl+C or SIGTERM.';

        if ($interactive && \function_exists('pcntl_signal')) {
            $instructions .= ' Ctrl+C also shuts down cleanly.';
        }

        $io->note('Inspection session is running. '.$instructions);
    }

    private function waitForStop(InputInterface $input): void
    {
        if (!$input->isInteractive()) {
            while (!$this->isStopRequested()) {
                usleep(200_000);
            }

            return;
        }

        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $stream ??= STDIN;

        if ('Windows' === PHP_OS_FAMILY) {
            fgets($stream);

            return;
        }

        while (!$this->isStopRequested()) {
            $read = [$stream];
            $write = $except = [];
            $ready = @stream_select($read, $write, $except, 0, 200_000);

            if (false === $ready && !$this->isStopRequested()) {
                throw new \RuntimeException('Could not read inspection input.');
            }

            if ($ready > 0) {
                fgets($stream);

                return;
            }
        }
    }

    private function isStopRequested(): bool
    {
        return $this->stopRequested;
    }
}
