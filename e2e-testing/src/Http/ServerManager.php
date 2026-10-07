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

use Contao\E2eTesting\Exception\E2eTestException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final readonly class ServerManager
{
    public function __construct(
        private Filesystem $filesystem = new Filesystem(),
        private FreePortFinder $portFinder = new FreePortFinder(),
        private string $appEnvironment = 'prod',
        private PhpServerConfig $phpServer = new PhpServerConfig(),
    ) {
    }

    public function start(string $directory, string $databaseUrl, string $runtimeDirectory): ServerProcess
    {
        $this->assertDocumentRootExists($directory);

        $routerFile = Path::join($runtimeDirectory, 'router.php');
        $this->filesystem->mkdir($runtimeDirectory);
        $this->filesystem->dumpFile($routerFile, $this->router($directory));

        $config = WebServerConfig::php($directory, router: $routerFile)
            ->withPhpServer($this->phpServer)
            ->withEnvironment([
                'APP_ENV' => $this->appEnvironment,
                'DATABASE_URL' => $databaseUrl,
                'DISABLE_HTTP_CACHE' => '1',
                'XDEBUG_MODE' => 'off',
            ])
        ;
        $server = (new WebServerManager($this->portFinder, $this->filesystem))->start($config);

        return new ServerProcess($server, $server->port);
    }

    private function assertDocumentRootExists(string $directory): void
    {
        $frontController = Path::join($directory, 'public/index.php');

        if (!is_file($frontController)) {
            throw new E2eTestException(\sprintf('The Contao E2E installation is incomplete because "%s" is missing. Clear the reusable installation cache with "vendor/bin/contao-e2e cache:clear" and run the tests again.', $frontController));
        }
    }

    private function router(string $directory): string
    {
        return PhpRouter::generate(Path::join($directory, 'public/index.php'));
    }
}
