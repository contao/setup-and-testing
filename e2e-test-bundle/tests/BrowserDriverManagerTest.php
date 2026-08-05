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

use Contao\E2eTestBundle\Browser\BrowserDriverManager;
use Contao\E2eTestBundle\Browser\BrowserType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class BrowserDriverManagerTest extends TestCase
{
    public function testInstallsAndReusesFirefoxDriver(): void
    {
        $filesystem = new Filesystem();
        $directory = sys_get_temp_dir().'/contao-e2e-driver-'.bin2hex(random_bytes(8));
        $bdi = $directory.'/bdi.php';
        $log = $directory.'/calls.log';
        $filesystem->dumpFile($bdi, <<<'PHP_WRAP'
            <?php

            file_put_contents(__DIR__.'/calls.log', "called\n", FILE_APPEND);
            $driver = $argv[2].'/'.('Windows' === PHP_OS_FAMILY ? 'geckodriver.exe' : 'geckodriver');
            file_put_contents($driver, 'driver');

            if ('Windows' !== PHP_OS_FAMILY) {
                chmod($driver, 0755);
            }
            PHP_WRAP);

        try {
            $manager = new BrowserDriverManager(bdiBinary: $bdi, discoverInstalledDrivers: false);
            $driver = $manager->firefox($directory);

            $this->assertSame(Path::join($directory, 'drivers', BrowserType::Firefox->driverFilename()), $driver);
            $this->assertSame($driver, $manager->firefox($directory));
            $this->assertSame("called\n", file_get_contents($log));
        } finally {
            $filesystem->remove($directory);
        }
    }
}
