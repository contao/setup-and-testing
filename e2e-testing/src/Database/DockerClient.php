<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Database;

use Contao\E2eTesting\Exception\DockerUnavailableException;
use Symfony\Component\Process\Process;

final readonly class DockerClient
{
    /**
     * @param non-empty-list<string> $command
     */
    public function __construct(private array $command = ['docker'])
    {
    }

    public function isRunning(string $container): bool
    {
        $state = $this->inspect($container);

        return $state && $state->running;
    }

    public function exists(string $container): bool
    {
        return null !== $this->inspect($container);
    }

    public function inspect(string $container): DockerContainerState|null
    {
        $json = $this->optional(['inspect', '--format', '{{json .}}', $container]);

        return null === $json ? null : DockerContainerState::fromJson($json);
    }

    public function start(string $container): void
    {
        $this->run(['start', $container]);
    }

    public function create(string $container, DockerDatabaseConfig $config, string $databaseDirectory): void
    {
        $this->run([
            'run', '--detach', '--name', $container,
            '--env', $config->rootPasswordEnvironment.'=contao-e2e',
            '--label', DockerContainerState::DATABASE_CONFIG_LABEL.'='.$config->fingerprint(),
            '--label', DockerContainerState::DATABASE_PATH_LABEL.'='.hash('sha256', $databaseDirectory),
            '--mount', 'type=bind,source='.$databaseDirectory.',target=/var/lib/mysql',
            '--publish', '127.0.0.1::3306',
            $config->image,
            '--character-set-server=utf8mb4',
            '--collation-server=utf8mb4_unicode_ci',
        ]);
    }

    public function usesDatabaseConfiguration(string $container, DockerDatabaseConfig $config, string $databaseDirectory): bool
    {
        return $this->inspect($container)?->usesDatabaseConfiguration($config, $databaseDirectory) ?? false;
    }

    public function port(string $container): int
    {
        return $this->inspect($container)?->port() ?? throw new DockerUnavailableException('Docker did not expose a MariaDB port for the Contao E2E container.');
    }

    public function stop(string $container): void
    {
        if ($this->isRunning($container)) {
            $this->run(['stop', $container]);
        }
    }

    public function remove(string $container): void
    {
        $this->stop($container);

        if ($this->exists($container)) {
            $this->run(['rm', '--volumes', $container]);
        }
    }

    /**
     * @return list<string>
     */
    public function find(string $name): array
    {
        $output = $this->run(['ps', '--all', '--filter', 'name='.$name, '--format', '{{.Names}}']);

        return '' === $output ? [] : explode("\n", $output);
    }

    /**
     * @param list<string> $arguments
     */
    private function optional(array $arguments): string|null
    {
        $process = $this->process($arguments);

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    /**
     * @param list<string> $arguments
     */
    private function run(array $arguments): string
    {
        $process = $this->process($arguments);
        $process->setTimeout(300);

        try {
            $process->run();
        } catch (\Throwable $exception) {
            throw new DockerUnavailableException('Docker is not installed or cannot be executed.', 0, $exception);
        }

        if (!$process->isSuccessful()) {
            throw new DockerUnavailableException("Docker could not provide MariaDB for the E2E tests:\n".$process->getErrorOutput());
        }

        return trim($process->getOutput());
    }

    /**
     * @param list<string> $arguments
     */
    private function process(array $arguments): Process
    {
        return new Process([...$this->command, ...$arguments]);
    }
}
