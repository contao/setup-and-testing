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

use Contao\E2eTesting\Process\ContaoConsole;
use Contao\E2eTesting\Process\ProcessRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ContaoConsoleTest extends TestCase
{
    public function testSetupUsesTheSelectedAppEnvironment(): void
    {
        $directory = sys_get_temp_dir().'/contao-e2e-console-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($directory.'/vendor/bin/contao-setup', '<?php file_put_contents(__DIR__."/../../environment", getenv("APP_ENV"));');

        try {
            (new ContaoConsole(new ProcessRunner(), 'dev'))->setup($directory, 'mysql://localhost/test');
            $this->assertSame('dev', file_get_contents($directory.'/environment'));

            (new ContaoConsole(new ProcessRunner()))->setup($directory, 'mysql://localhost/test');
            $this->assertSame('prod', file_get_contents($directory.'/environment'));
        } finally {
            $filesystem->remove($directory);
        }
    }
}
