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

use Contao\E2eTesting\Application\ApplicationConfigInterface;
use Contao\E2eTesting\Application\ApplicationInterface;
use Contao\E2eTesting\Application\ApplicationRuntime;
use Contao\E2eTesting\Exception\InspectionInterruptedException;
use Contao\E2eTesting\Inspection\InspectionDaemonManager;
use Contao\E2eTesting\Inspection\InspectionDefinitionLoader;
use Contao\E2eTesting\Inspection\InspectionDetails;
use Contao\E2eTesting\Inspection\InspectionSession;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('server:start', 'Prepare an application and keep it available for manual inspection')]
final class ServerStartCommand extends AbstractWorkspaceCommand implements SignalableCommandInterface
{
    private bool $stopRequested = false;

    private bool $preparing = false;

    public function __construct(private readonly InspectionSession|null $session = null)
    {
        parent::__construct();
    }

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
        $this->addArgument('inspection-file', InputArgument::REQUIRED, 'PHP file returning an ApplicationConfigInterface or a factory callable');
        $this->addOption('daemon', 'd', InputOption::VALUE_NONE, 'Prepare and run the inspection session in the background');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('daemon')) {
            $store = (new InspectionDaemonManager())->start((string) $input->getArgument('inspection-file'), $this->cache());
            (new SymfonyStyle($input, $output))->success('Inspection worker started. Use server:status for readiness and URLs, and server:stop to shut down.');
            $output->writeln('Log: '.$store->logFile());

            return self::SUCCESS;
        }

        return $this->runInspection($input, $output);
    }

    private function runInspection(InputInterface $input, OutputInterface $output): int
    {
        $this->stopRequested = false;

        if ($this->isStopRequested()) {
            return self::SUCCESS;
        }

        if (!$this->session && !$input->isInteractive() && !\function_exists('pcntl_signal')) {
            throw new \InvalidArgumentException('Non-interactive inspection requires PCNTL for clean shutdown. Run interactively and press Enter instead.');
        }

        $runtime = ApplicationRuntime::create();
        $application = null;

        try {
            $this->preparing = true;

            try {
                $application = $this->createApplication((string) $input->getArgument('inspection-file'), $runtime);
            } finally {
                $this->preparing = false;
            }

            if (!$this->isStopRequested()) {
                $this->session?->ready($application);
                $this->describeApplication($application, new SymfonyStyle($input, $output), $input->isInteractive());
                $this->waitForStop($input);
            }
        } catch (InspectionInterruptedException) {
            return self::SUCCESS;
        } finally {
            try {
                $application?->release();
            } finally {
                $runtime->close();
            }
        }

        return self::SUCCESS;
    }

    private function createApplication(string $file, ApplicationRuntime $runtime): ApplicationInterface
    {
        $definition = (new InspectionDefinitionLoader())->load($file);
        $application = $definition instanceof \Closure ? $definition($runtime) : $definition;

        if ($application instanceof ApplicationConfigInterface) {
            $application = $runtime->createApplication($application);
        }

        if (!$application instanceof ApplicationInterface) {
            throw new \InvalidArgumentException('The inspection factory must return an ApplicationConfigInterface or an ApplicationInterface.');
        }

        if ($application->runtime() !== $runtime) {
            $application->release();

            throw new \InvalidArgumentException('The inspection factory must use the supplied ApplicationRuntime.');
        }

        try {
            $application->uri();

            return $application;
        } catch (\Throwable $exception) {
            $application->release();

            throw $exception;
        }
    }

    private function describeApplication(ApplicationInterface $application, SymfonyStyle $io, bool $interactive): void
    {
        $io->title('Application ready for inspection');
        $io->definitionList(...InspectionDetails::forApplication($application)->rows());

        $instructions = $this->session ? 'Stop with server:stop.' : ($interactive ? 'Press Enter to stop.' : 'Stop with Ctrl+C or SIGTERM.');

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
        return $this->stopRequested || ($this->session?->stopRequested() ?? false);
    }
}
