<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Tests;

use Contao\E2eTestBundle\Exception\E2eTestException;
use Contao\E2eTestBundle\Http\ServerManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class ServerManagerTest extends TestCase
{
    public function testReportsAnIncompleteInstallation(): void
    {
        $filesystem = new Filesystem();
        $directory = sys_get_temp_dir().'/contao-e2e-server-'.bin2hex(random_bytes(8));
        $filesystem->mkdir($directory);

        try {
            (new ServerManager($filesystem))->start($directory, 'sqlite:///:memory:', $directory.'/runtime');
            $this->fail('Starting the server without a front controller should fail.');
        } catch (E2eTestException $exception) {
            $this->assertSame(
                'The Contao E2E installation is incomplete because "'.Path::join($directory, 'public/index.php').'" is missing. Clear the reusable installation cache with "vendor/bin/contao-e2e cache:clear" and run the tests again.',
                $exception->getMessage(),
            );
        } finally {
            $filesystem->remove($directory);
        }
    }

    public function testDisablesXdebug(): void
    {
        $filesystem = new Filesystem();
        $directory = sys_get_temp_dir().'/contao-e2e-server-'.bin2hex(random_bytes(8));
        $filesystem->dumpFile($directory.'/public/index.php', '<?php echo getenv("XDEBUG_MODE");');
        $server = (new ServerManager($filesystem))->start($directory, 'sqlite:///:memory:', $directory.'/runtime');

        try {
            $this->assertSame('off', file_get_contents('http://127.0.0.1:'.$server->port.'/'));
        } finally {
            $server->stop();
            $filesystem->remove($directory);
        }
    }

    public function testServerOutputCannotBlockRequests(): void
    {
        $filesystem = new Filesystem();
        $directory = sys_get_temp_dir().'/contao-e2e-server-'.bin2hex(random_bytes(8));
        $filesystem->dumpFile(
            $directory.'/public/index.php',
            '<?php error_log(str_repeat("x", 8192)); echo "OK";',
        );

        $server = (new ServerManager($filesystem))->start($directory, 'sqlite:///:memory:', $directory.'/runtime');

        try {
            for ($i = 0; $i < 20; ++$i) {
                $this->assertSame('OK', file_get_contents('http://127.0.0.1:'.$server->port.'/'));
            }
        } finally {
            $server->stop();
            $filesystem->remove($directory);
        }
    }
}
