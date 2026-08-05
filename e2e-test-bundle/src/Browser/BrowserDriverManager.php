<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Browser;

use Composer\InstalledVersions;
use Contao\E2eTestBundle\Exception\BrowserDriverException;
use Contao\E2eTestBundle\Process\ProcessRunner;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\ExecutableFinder;

final readonly class BrowserDriverManager
{
    public function __construct(
        private ProcessRunner $processRunner = new ProcessRunner(),
        private string|null $bdiBinary = null,
        private bool $discoverInstalledDrivers = true,
    ) {
    }

    public function firefox(string $workspace): string
    {
        return $this->driver(BrowserType::Firefox, $workspace);
    }

    public function chrome(string $workspace): string
    {
        return $this->driver(BrowserType::Chrome, $workspace);
    }

    private function driver(BrowserType $browser, string $workspace): string
    {
        $filename = $browser->driverFilename();
        $cachedDriver = Path::join($workspace, 'drivers', $filename);

        if ($this->isExecutable($cachedDriver)) {
            return $cachedDriver;
        }

        $installedDriver = $this->discoverInstalledDrivers
            ? (new ExecutableFinder())->find($filename, null, ['./drivers', './vendor/bin'])
            : null;

        if (null !== $installedDriver) {
            return $installedDriver;
        }

        return $this->install($browser, $workspace, $cachedDriver);
    }

    private function install(BrowserType $browser, string $workspace, string $driver): string
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir([Path::getDirectory($driver), Path::join($workspace, 'locks')]);

        $lock = fopen(Path::join($workspace, 'locks/browser-drivers.lock'), 'c+');

        if (false === $lock) {
            throw new BrowserDriverException('Could not create the browser driver installation lock.');
        }

        try {
            flock($lock, LOCK_EX);

            if (!$this->isExecutable($driver)) {
                $this->installDriver($browser, Path::getDirectory($driver));
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        if (!$this->isExecutable($driver)) {
            throw new BrowserDriverException(\sprintf('BDI did not install the expected browser driver at "%s".', $driver));
        }

        return $driver;
    }

    private function installDriver(BrowserType $browser, string $directory): void
    {
        $browserPath = $browser->installerBrowserPath();
        $command = [
            PHP_BINARY,
            $this->resolveBdiBinary(),
            $browser->installCommand($browserPath),
            $directory,
            '--no-interaction',
        ];

        if (null !== $browserPath) {
            $command[] = '--browser-path='.$browserPath;
        }

        $this->processRunner->run($command, $directory);
    }

    private function resolveBdiBinary(): string
    {
        if (null !== $this->bdiBinary) {
            return $this->bdiBinary;
        }

        $installPath = InstalledVersions::isInstalled('dbrekelmans/bdi')
            ? InstalledVersions::getInstallPath('dbrekelmans/bdi')
            : null;
        $binary = null === $installPath
            ? (new ExecutableFinder())->find('bdi')
            : Path::join($installPath, 'bdi');

        if (null === $binary || !is_file($binary)) {
            throw new BrowserDriverException('Could not locate the BDI browser driver installer.');
        }

        return $binary;
    }

    private function isExecutable(string $path): bool
    {
        return is_file($path) && ('Windows' === PHP_OS_FAMILY || is_executable($path));
    }
}
