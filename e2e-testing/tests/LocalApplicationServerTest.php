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

use Contao\E2eTesting\Application\Application;
use Contao\E2eTesting\Application\ApplicationConfig;
use Contao\E2eTesting\Application\LocalApplicationConfig;
use Contao\E2eTesting\Browser\BrowserRuntime;
use Contao\E2eTesting\Browser\BrowserSession;
use Contao\E2eTesting\Browser\BrowserSessionFactoryInterface;
use Contao\E2eTesting\Exception\E2eTestException;
use Contao\E2eTesting\Http\WebServerConfig;
use Contao\E2eTesting\Http\WebServerManager;
use PHPUnit\Framework\TestCase;
use Playwright\Browser\BrowserContextInterface;
use Playwright\Page\PageInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class LocalApplicationServerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $filesystem = new Filesystem();
        $this->directory = $filesystem->tempnam(sys_get_temp_dir(), 'local application ');
        $filesystem->remove($this->directory);
        $filesystem->mkdir($this->directory.'/public');
        $filesystem->dumpFile($this->directory.'/public/index.php', '<?php echo json_encode(["uri" => $_SERVER["REQUEST_URI"], "env" => getenv("LOCAL_TEST_ENV")]);');
        $filesystem->dumpFile($this->directory.'/public/style.css', 'body { color: orange; }');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testPhpServerHandlesFrontControllerRoutesAndStaticFilesAndStops(): void
    {
        $original = WebServerConfig::php($this->directory);
        $config = $original->withEnvironment(['LOCAL_TEST_ENV' => 'test']);
        $this->assertSame([], $original->environment());
        $server = (new WebServerManager())->start($config);

        try {
            $response = HttpClient::create()->request('GET', $server->baseUri.'/api/data.json?example=1');
            $this->assertSame(['uri' => '/api/data.json?example=1', 'env' => 'test'], $response->toArray());
            $this->assertSame('body { color: orange; }', HttpClient::create()->request('GET', $server->baseUri.'/style.css')->getContent());
        } finally {
            $server->stop();
        }

        $this->assertStopped($server->baseUri);
    }

    public function testPhpServerPassesDoubleSlashRequestsToTheFrontController(): void
    {
        $server = (new WebServerManager())->start(WebServerConfig::php($this->directory));

        try {
            $response = HttpClient::create()->request('GET', $server->baseUri.'//');
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('//', $response->toArray()['uri']);
        } finally {
            $server->stop();
        }
    }

    public function testServerLoggingCannotBlockRequests(): void
    {
        (new Filesystem())->dumpFile($this->directory.'/public/index.php', '<?php error_log(str_repeat("x", 8192)); echo "OK";');
        $server = (new WebServerManager())->start(WebServerConfig::php($this->directory));

        try {
            $client = HttpClient::create(['timeout' => 1]);

            for ($i = 0; $i < 20; ++$i) {
                $this->assertSame('OK', $client->request('GET', $server->baseUri)->getContent());
            }
        } finally {
            $server->stop();
        }
    }

    public function testCustomCommandReceivesItsAllocatedPort(): void
    {
        $config = WebServerConfig::command([PHP_BINARY, '-S', '127.0.0.1:{port}', '-t', 'public'], $this->directory);
        $server = (new WebServerManager())->start($config);

        try {
            $this->assertSame(200, HttpClient::create()->request('GET', $server->baseUri)->getStatusCode());
        } finally {
            $server->stop();
        }
    }

    public function testApplicationResetKeepsServerRunningAndReleaseStopsIt(): void
    {
        $server = (new WebServerManager())->start(WebServerConfig::php($this->directory));
        $context = $this->createMock(BrowserContextInterface::class);
        $context
            ->expects($this->once())
            ->method('close')
        ;
        $session = new BrowserSession($server->baseUri, $context, $this->createStub(PageInterface::class));
        $factory = $this->createMock(BrowserSessionFactoryInterface::class);
        $factory
            ->expects($this->once())
            ->method('create')
            ->willReturn($session)
        ;
        $application = new Application(ApplicationConfig::create($server->baseUri), new BrowserRuntime('/unused', $factory), $server);

        try {
            $this->assertSame($server->baseUri.'/login', $application->createBrowser()->uri('/login'));
            $application->resetState();
            $this->assertSame(200, HttpClient::create()->request('GET', $server->baseUri)->getStatusCode());
        } finally {
            $application->release();
        }

        $this->assertStopped($server->baseUri);
    }

    public function testSeparateApplicationsUseIndependentPorts(): void
    {
        $manager = new WebServerManager();
        $first = $manager->start(WebServerConfig::php($this->directory));

        try {
            $second = $manager->start(WebServerConfig::php($this->directory));

            try {
                $this->assertNotSame($first->baseUri, $second->baseUri);
                $this->assertSame(200, HttpClient::create()->request('GET', $first->baseUri)->getStatusCode());
                $this->assertSame(200, HttpClient::create()->request('GET', $second->baseUri)->getStatusCode());
            } finally {
                $second->stop();
            }
        } finally {
            $first->stop();
        }
    }

    public function testServerStopsEvenWhenBrowserCleanupFails(): void
    {
        $server = (new WebServerManager())->start(WebServerConfig::php($this->directory));
        $factory = $this->createMock(BrowserSessionFactoryInterface::class);
        $factory
            ->expects($this->once())
            ->method('close')
            ->willThrowException(new \RuntimeException('browser cleanup failed'))
        ;
        $application = new Application(ApplicationConfig::create($server->baseUri), new BrowserRuntime('/unused', $factory), $server);

        try {
            $application->release();
            $this->fail('Browser cleanup should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('browser cleanup failed', $exception->getMessage());
            $port = parse_url($server->baseUri, PHP_URL_PORT);
            $this->assertFalse(@stream_socket_client('tcp://127.0.0.1:'.$port));
        } finally {
            $server->stop();
        }

        $this->assertStopped($server->baseUri);
    }

    public function testFailedStartupReportsExitCode(): void
    {
        $config = WebServerConfig::command([PHP_BINARY, '-r', 'fwrite(STDERR, "startup failed"); exit(1);', '{port}'], $this->directory);
        $this->expectException(E2eTestException::class);
        $this->expectExceptionMessage('exit code 1');
        (new WebServerManager())->start($config);
    }

    public function testLocalConfigurationPassesEnvironmentAndOwnsItsServer(): void
    {
        $script = <<<'PHP'
            <?php
            file_put_contents(__DIR__.'/server.json', json_encode(['port' => $argv[1], 'env' => getenv('LOCAL_TEST_ENV')]));
            $server = stream_socket_server('tcp://127.0.0.1:'.$argv[1]);
            while ($client = stream_socket_accept($server)) {
                fclose($client);
            }
            PHP;
        (new Filesystem())->dumpFile($this->directory.'/server.php', $script);
        $config = LocalApplicationConfig::command([PHP_BINARY, 'server.php', '{port}'], $this->directory)
            ->withEnvironment(['LOCAL_TEST_ENV' => 'test'])
        ;
        $application = $config->createApplication();

        try {
            $metadata = json_decode((string) file_get_contents($this->directory.'/server.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('test', $metadata['env']);
            $port = $metadata['port'];
            $socket = stream_socket_client('tcp://127.0.0.1:'.$port);
            $this->assertIsResource($socket);
            fclose($socket);
        } finally {
            $application->release();
        }

        $this->assertFalse(@stream_socket_client('tcp://127.0.0.1:'.$port));
    }

    public function testCustomRouterIsKeptAfterShutdown(): void
    {
        $router = $this->directory.'/router.php';
        (new Filesystem())->dumpFile($router, '<?php echo "custom router";');
        $server = (new WebServerManager())->start(WebServerConfig::php($this->directory, router: 'router.php'));

        try {
            $this->assertSame('custom router', HttpClient::create()->request('GET', $server->baseUri.'/anything')->getContent());
        } finally {
            $server->stop();
        }

        $this->assertFileExists($router);
    }

    public function testGeneratedRouterIsRemovedAfterShutdown(): void
    {
        $router = (new Filesystem())->tempnam($this->directory, 'router-');
        $origins = (new Filesystem())->tempnam($this->directory, 'origins-');
        $filesystem = $this->getMockBuilder(Filesystem::class)->onlyMethods(['tempnam'])->getMock();
        $filesystem
            ->expects($this->atLeastOnce())
            ->method('tempnam')
            ->willReturnCallback(static fn (string $directory, string $prefix): string => match ($prefix) {
                'contao-e2e-router-' => $router,
                'contao-e2e-origins-' => $origins,
                default => (new Filesystem())->tempnam($directory, $prefix),
            })
        ;
        $server = (new WebServerManager(filesystem: $filesystem))->start(WebServerConfig::php($this->directory));

        try {
            $this->assertFileExists($router);
            $this->assertFileExists($origins);
            $this->assertSame(200, HttpClient::create()->request('GET', $server->baseUri.'/route')->getStatusCode());
        } finally {
            $server->stop();
        }

        $this->assertFileDoesNotExist($router);
        $this->assertFileDoesNotExist($origins);
    }

    public function testRouterWriteFailureRemovesTheTemporaryFile(): void
    {
        $router = (new Filesystem())->tempnam($this->directory, 'router-');
        $origins = (new Filesystem())->tempnam($this->directory, 'origins-');
        $filesystem = $this->getMockBuilder(Filesystem::class)->onlyMethods(['tempnam', 'dumpFile'])->getMock();
        $filesystem
            ->expects($this->atLeastOnce())
            ->method('tempnam')
            ->willReturnCallback(static fn (string $directory, string $prefix): string => match ($prefix) {
                'contao-e2e-router-' => $router,
                'contao-e2e-origins-' => $origins,
                default => (new Filesystem())->tempnam($directory, $prefix),
            })
        ;

        $filesystem
            ->expects($this->exactly(2))
            ->method('dumpFile')
            ->willReturnCallback(
                static function (string $file, string $content) use ($router): void {
                    if ($file === $router) {
                        throw new \RuntimeException('router write failed');
                    }
                    (new Filesystem())->dumpFile($file, $content);
                },
            )
        ;

        try {
            (new WebServerManager(filesystem: $filesystem))->start(WebServerConfig::php($this->directory));
            $this->fail('Router creation should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('router write failed', $exception->getMessage());
            $this->assertFileDoesNotExist($router);
            $this->assertFileDoesNotExist($origins);
        }
    }

    private function assertStopped(string $baseUri): void
    {
        $this->expectException(TransportExceptionInterface::class);
        HttpClient::create(['timeout' => 1])->request('GET', $baseUri)->getStatusCode();
    }
}
