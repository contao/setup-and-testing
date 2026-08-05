<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Http;

use Contao\E2eTestBundle\Exception\E2eTestException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

final readonly class ServerManager
{
    public function __construct(private Filesystem $filesystem = new Filesystem())
    {
    }

    public function start(string $directory, string $databaseUrl, string $runtimeDirectory): ServerProcess
    {
        $port = $this->findPort();
        $mappingFile = Path::join($runtimeDirectory, 'origins.json');
        $routerFile = Path::join($runtimeDirectory, 'router.php');
        $this->filesystem->mkdir($runtimeDirectory);
        $this->filesystem->dumpFile($mappingFile, "{}\n");
        $this->filesystem->dumpFile($routerFile, $this->router($directory, $mappingFile));

        $process = new Process(
            [
                PHP_BINARY,
                '-S',
                '127.0.0.1:'.$port,
                '-t',
                Path::join($directory, 'public'),
                $routerFile,
            ],
            $directory,
            [
                'APP_ENV' => 'prod',
                'DATABASE_URL' => $databaseUrl,
                'DISABLE_HTTP_CACHE' => '1',
                'XDEBUG_MODE' => 'off',
            ],
        );
        $process->setTimeout(null);
        $process->disableOutput();
        $process->start();
        $this->waitUntilListening($process, $port);

        return new ServerProcess($process, $port, $mappingFile);
    }

    private function findPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if (false === $socket) {
            throw new E2eTestException(\sprintf('Could not reserve a web server port: %s', $errorMessage));
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    private function waitUntilListening(Process $process, int $port): void
    {
        $deadline = microtime(true) + 15;

        do {
            if (!$process->isRunning()) {
                throw new E2eTestException('The Contao E2E web server stopped during startup.');
            }

            $socket = @stream_socket_client('tcp://127.0.0.1:'.$port, $errorCode, $errorMessage, 0.1);

            if (false !== $socket) {
                fclose($socket);

                return;
            }

            usleep(20000);
        } while (microtime(true) < $deadline);

        throw new E2eTestException('The Contao E2E web server did not become ready.');
    }

    private function router(string $directory, string $mappingFile): string
    {
        $index = var_export(Path::join($directory, 'public/index.php'), true);
        $mapping = var_export($mappingFile, true);

        return <<<PHP
            <?php

            declare(strict_types=1);

            \$mapping = json_decode((string) file_get_contents($mapping), true, 512, JSON_THROW_ON_ERROR);
            \$transportHost = explode(':', \$_SERVER['HTTP_HOST'] ?? '')[0];

            if (isset(\$mapping[\$transportHost])) {
                \$_SERVER['HTTP_HOST'] = \$mapping[\$transportHost]['host'];

                if (\$mapping[\$transportHost]['https']) {
                    \$_SERVER['HTTPS'] = 'on';
                    \$_SERVER['SERVER_PORT'] = '443';
                }
            }

            if ('/' !== \$_SERVER['REQUEST_URI'] && is_file(\$_SERVER['DOCUMENT_ROOT'].parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
                return false;
            }

            require $index;
            PHP;
    }
}
