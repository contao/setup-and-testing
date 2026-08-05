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
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class BrowserDriverManagerTest extends TestCase
{
    public function testInstallsAndReusesFirefoxDriver(): void
    {
        $filesystem = new Filesystem();
        $directory = sys_get_temp_dir().'/contao-e2e-driver-'.bin2hex(random_bytes(8));
        $bdi = $directory.'/bdi.php';
        $log = $directory.'/calls.log';
        $filesystem->dumpFile($bdi, <<<'PHP'
            <?php

            file_put_contents(__DIR__.'/calls.log', "called\n", FILE_APPEND);
            file_put_contents($argv[2].'/geckodriver', 'driver');
            chmod($argv[2].'/geckodriver', 0755);
            PHP);

        try {
            $manager = new BrowserDriverManager(bdiBinary: $bdi, discoverInstalledDrivers: false);
            $driver = $manager->firefox($directory);

            $this->assertSame($directory.'/drivers/geckodriver', $driver);
            $this->assertSame($driver, $manager->firefox($directory));
            $this->assertSame("called\n", file_get_contents($log));
        } finally {
            $filesystem->remove($directory);
        }
    }
}
