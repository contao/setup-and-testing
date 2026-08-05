<?php

declare(strict_types=1);

namespace Contao\E2eTestBundle\Database;

use Contao\E2eTestBundle\Exception\DockerUnavailableException;
use Symfony\Component\Process\Process;

final readonly class DockerClient
{
    private const DATABASE_PATH_LABEL = 'org.contao.e2e.database-path';

    private const DATABASE_CONFIG_LABEL = 'org.contao.e2e.database-config';

    public function isRunning(string $container): bool
    {
        return 'true' === $this->optional(['inspect', '--format', '{{.State.Running}}', $container]);
    }

    public function exists(string $container): bool
    {
        return null !== $this->optional(['inspect', '--format', '{{.Id}}', $container]);
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
            '--label', self::DATABASE_CONFIG_LABEL.'='.$config->fingerprint(),
            '--label', self::DATABASE_PATH_LABEL.'='.hash('sha256', $databaseDirectory),
            '--mount', 'type=bind,source='.$databaseDirectory.',target=/var/lib/mysql',
            '--publish', '127.0.0.1::3306',
            $config->image,
            '--character-set-server=utf8mb4',
            '--collation-server=utf8mb4_unicode_ci',
        ]);
    }

    public function usesDatabaseConfiguration(string $container, DockerDatabaseConfig $config, string $databaseDirectory): bool
    {
        $path = $this->label($container, self::DATABASE_PATH_LABEL);
        $configuration = $this->label($container, self::DATABASE_CONFIG_LABEL);

        return null !== $path
            && null !== $configuration
            && hash_equals(hash('sha256', $databaseDirectory), $path)
            && hash_equals($config->fingerprint(), $configuration);
    }

    public function port(string $container): int
    {
        $port = $this->run(['inspect', '--format', '{{(index (index .NetworkSettings.Ports "3306/tcp") 0).HostPort}}', $container]);

        if (!ctype_digit($port)) {
            throw new DockerUnavailableException('Docker did not expose a MariaDB port for the Contao E2E container.');
        }

        return (int) $port;
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

    private function label(string $container, string $label): string|null
    {
        return $this->optional(['inspect', '--format', '{{index .Config.Labels "'.$label.'"}}', $container]);
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
        return new Process(['docker', ...$arguments]);
    }
}
