<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Mail;

use Contao\E2eTesting\Cache\CacheConfig;
use Contao\E2eTesting\Docker\DockerServiceInterface;
use Contao\E2eTesting\Exception\MailCaptureException;
use Contao\E2eTesting\Process\ProcessRunner;
use Contao\E2eTesting\Process\ProcessRunnerInterface;
use Symfony\Component\HttpClient\HttpClient;

final readonly class DockerMailCaptureService implements DockerServiceInterface
{
    public function __construct(
        private CacheConfig $cache,
        private DockerMailCaptureConfig $config = new DockerMailCaptureConfig(),
        private ProcessRunnerInterface $runner = new ProcessRunner(),
    ) {
    }

    public function fingerprint(): string
    {
        return hash('sha256', serialize(['mail-capture', $this->cache->projectDirectory, $this->cache->rootDirectory, $this->config]));
    }

    public function warmUp(): void
    {
        $this->connection();
    }

    public function connection(): MailCaptureConfig
    {
        // Each PHP process owns its containers so parallel workers never share an inbox
        static $connections = [];
        $key = $this->fingerprint();

        return $connections[$key] ??= $this->start();
    }

    private function start(): MailCaptureConfig
    {
        $name = 'contao-e2e-mail-capture-'.bin2hex(random_bytes(12));

        try {
            $this->run([
                'run', '--detach', '--rm', '--name', $name,
                '--publish', '127.0.0.1::1025', '--publish', '127.0.0.1::8025',
                '--env', 'MP_DATABASE=:memory:', '--env', 'MP_MAX_MESSAGES=10000',
                $this->config->image,
            ]);
            $connection = $this->inspect($name);
            $this->waitUntilReady($connection);
        } catch (\Throwable $exception) {
            $this->remove($name);

            throw new MailCaptureException('Could not start the mail capture Docker container: '.$exception->getMessage(), 0, $exception);
        }

        register_shutdown_function(fn () => $this->remove($name));

        return $connection;
    }

    private function inspect(string $name): MailCaptureConfig
    {
        $ports = json_decode($this->run(['inspect', '--format', '{{json .NetworkSettings.Ports}}', $name]), true, 512, JSON_THROW_ON_ERROR);

        return new MailCaptureConfig(
            'http://127.0.0.1:'.$this->port($ports, '8025/tcp'),
            'smtp://127.0.0.1:'.$this->port($ports, '1025/tcp'),
        );
    }

    private function port(mixed $ports, string $name): int
    {
        $port = \is_array($ports) ? ($ports[$name][0]['HostPort'] ?? null) : null;

        if (!\is_string($port) || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new MailCaptureException('Docker did not publish the mail capture port '.$name);
        }

        return (int) $port;
    }

    private function waitUntilReady(MailCaptureConfig $connection): void
    {
        $client = HttpClient::create(['timeout' => 1, 'max_duration' => 1]);
        $deadline = hrtime(true) / 1e9 + 10;
        $lastException = null;

        do {
            try {
                if (200 === $client->request('GET', $connection->apiUrl.'/readyz')->getStatusCode()) {
                    return;
                }
            } catch (\Throwable $exception) {
                $lastException = $exception;
            }

            usleep(100000);
        } while (hrtime(true) / 1e9 < $deadline);

        throw new MailCaptureException('Mail capture did not become ready at '.$connection->apiUrl, 0, $lastException);
    }

    private function remove(string $name): void
    {
        try {
            $this->run(['rm', '--force', $name]);
        } catch (\Throwable) {
            // Cleanup must not hide the original test or startup failure
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function run(array $arguments): string
    {
        return trim($this->runner->run(['docker', ...$arguments], $this->cache->projectDirectory));
    }
}
