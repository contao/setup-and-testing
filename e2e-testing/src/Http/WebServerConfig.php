<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Http;

use Symfony\Component\Filesystem\Path;

final class WebServerConfig
{
    private string|null $documentRoot = null;

    private string|null $router = null;

    private PhpServerConfig|null $phpServer = null;

    /**
     * @param list<string>                $command
     * @param array<string, string|false> $environment
     */
    private function __construct(
        private array $command,
        public readonly string $directory,
        private array $environment = [],
    ) {
    }

    public static function php(string $directory, string $documentRoot = 'public', string|null $router = null): self
    {
        $config = self::command([PHP_BINARY, '-S', '127.0.0.1:{port}'], $directory);
        $root = realpath(Path::makeAbsolute($documentRoot, $config->directory));

        if (false === $root || !is_dir($root)) {
            throw new \InvalidArgumentException('The PHP web server document root must be an existing directory.');
        }

        $config->documentRoot = $root;
        $config->command = [...$config->command, '-t', $root];

        if (null !== $router) {
            $config->router = Path::makeAbsolute($router, $config->directory);

            if (!is_file($config->router)) {
                throw new \InvalidArgumentException('The PHP web server router must be an existing file.');
            }

            $config->command[] = $config->router;
        }

        return $config;
    }

    /**
     * @param list<string> $command
     */
    public static function command(array $command, string $directory): self
    {
        $root = realpath($directory);

        if (false === $root || !is_dir($root)) {
            throw new \InvalidArgumentException('The web server working directory must exist.');
        }

        if ([] === $command || !array_is_list($command)) {
            throw new \InvalidArgumentException('The web server command must be a non-empty list of arguments.');
        }

        foreach ($command as $argument) {
            if (!\is_string($argument) || '' === $argument) {
                throw new \InvalidArgumentException('Web server command arguments must be non-empty strings.');
            }
        }

        if (!str_contains(implode(' ', $command), '{port}')) {
            throw new \InvalidArgumentException('The web server command must contain a {port} placeholder.');
        }

        return new self($command, $root);
    }

    /**
     * @param array<string, string|false> $environment
     */
    public function withEnvironment(array $environment): self
    {
        $clone = clone $this;
        $clone->environment = $environment;

        return $clone;
    }

    public function withPhpServer(PhpServerConfig $phpServer): self
    {
        if (null === $this->documentRoot) {
            throw new \InvalidArgumentException('PHP settings can only be configured on a PHP web server.');
        }

        $clone = clone $this;
        $clone->phpServer = $phpServer;

        return $clone;
    }

    /**
     * @return list<string>
     */
    public function commandForPort(int $port, string|null $opcacheCacheId = null): array
    {
        $command = array_map(static fn (string $argument): string => str_replace('{port}', (string) $port, $argument), $this->command);

        if (null === $this->documentRoot) {
            return $command;
        }

        $phpServer = $this->phpServer ?? new PhpServerConfig();

        if (null !== $opcacheCacheId) {
            $phpServer = $phpServer->withIniSettings(['opcache.cache_id' => $opcacheCacheId]);
        }

        return [$command[0], ...$phpServer->arguments(), ...\array_slice($command, 1)];
    }

    /**
     * @return array<string, string|false>
     */
    public function environment(): array
    {
        return $this->environment;
    }

    public function frontController(): string|null
    {
        return null !== $this->documentRoot && null === $this->router ? Path::join($this->documentRoot, 'index.php') : null;
    }
}
