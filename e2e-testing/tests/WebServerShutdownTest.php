<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class WebServerShutdownTest extends TestCase
{
    public function testShutdownStopsServerEvenWhileReferencesAreRetained(): void
    {
        $script = <<<'PHP'
            require $argv[1];
            $server = (new \Contao\E2eTesting\Http\WebServerManager())->start(
                \Contao\E2eTesting\Http\WebServerConfig::php(__DIR__, __DIR__),
            );
            register_shutdown_function(static function () use ($server): void {
                $socket = @stream_socket_client('tcp://127.0.0.1:'.$server->port, $code, $message, 0.1);
                echo false === $socket ? 'stopped' : 'still running';
                if (false !== $socket) { fclose($socket); }
            });
            PHP;
        $process = new Process([PHP_BINARY, '-r', $script, \dirname(__DIR__, 2).'/vendor/autoload.php']);
        $process->mustRun();

        $this->assertSame('stopped', $process->getOutput());
    }
}
