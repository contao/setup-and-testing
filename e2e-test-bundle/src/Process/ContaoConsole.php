<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Process;

use Symfony\Component\Filesystem\Path;

final readonly class ContaoConsole
{
    public function __construct(private ProcessRunner $processRunner)
    {
    }

    public function setup(string $directory, string $databaseUrl): void
    {
        $this->processRunner->run(
            [PHP_BINARY, Path::join($directory, 'vendor/bin/contao-setup'), '--no-interaction'],
            $directory,
            $this->environment($databaseUrl),
        );
    }

    public function migrate(string $directory, string $databaseUrl): void
    {
        $console = Path::join($directory, 'vendor/bin/contao-console');
        $help = $this->processRunner->run([PHP_BINARY, $console, 'help', 'contao:migrate', '--format=txt'], $directory, $this->environment($databaseUrl));
        $command = [PHP_BINARY, $console, 'contao:migrate', '--no-interaction'];

        foreach (['--with-deletes', '--no-backup'] as $option) {
            if (str_contains($help, $option)) {
                $command[] = $option;
            }
        }

        $this->processRunner->run($command, $directory, $this->environment($databaseUrl));
    }

    /**
     * @param list<string> $paths
     */
    public function filesync(string $directory, string $databaseUrl, array $paths = []): void
    {
        $this->processRunner->run(
            [PHP_BINARY, Path::join($directory, 'vendor/bin/contao-console'), 'contao:filesync', ...$paths, '--no-interaction'],
            $directory,
            $this->environment($databaseUrl),
        );
    }

    /**
     * @return array<string, string>
     */
    private function environment(string $databaseUrl): array
    {
        return [
            'APP_ENV' => 'prod',
            'DATABASE_URL' => $databaseUrl,
            'DISABLE_HTTP_CACHE' => '1',
        ];
    }
}
