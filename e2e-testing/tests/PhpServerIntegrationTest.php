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

use Contao\E2eTesting\Application\ApplicationRuntime;
use Contao\E2eTesting\Application\LocalApplicationConfig;
use Contao\E2eTesting\Http\HttpRequest;
use Contao\E2eTesting\Http\PhpServerConfig;
use Contao\E2eTesting\Http\ServerManager;
use Contao\E2eTesting\Http\WebServerConfig;
use Contao\E2eTesting\Http\WebServerManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;

final class PhpServerIntegrationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/php settings '.bin2hex(random_bytes(6));
        (new Filesystem())->dumpFile($this->directory.'/public/index.php', <<<'PHP'
            <?php
            header('Content-Type: application/json');
            echo json_encode([
                'precision' => ini_get('precision'),
                'memory_limit' => ini_get('memory_limit'),
                'prefix' => ini_get('error_prepend_string'),
                'opcache' => function_exists('opcache_get_status') ? opcache_get_status(false) : false,
                'cache_id' => ini_get('opcache.cache_id'),
                'pid' => getmypid(),
                'app_env' => getenv('APP_ENV'),
                'database_url' => getenv('DATABASE_URL'),
            ]);
            PHP);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testLocalApplicationPassesIniOverridesToTheChildOnly(): void
    {
        $parentPrecision = \ini_get('precision');
        $php = (new PhpServerConfig())->withIniSettings([
            'precision' => 7,
            'memory_limit' => '192M',
            'error_prepend_string' => 'literal {port}; "quoted" = value',
        ]);
        $config = LocalApplicationConfig::php($this->directory)->withPhpServer($php);
        $runtime = ApplicationRuntime::create();
        $application = $runtime->createApplication($config);

        try {
            $values = $application->send(HttpRequest::get('/settings'))->toArray();
            $this->assertSame('7', $values['precision']);
            $this->assertSame('192M', $values['memory_limit']);
            $this->assertSame('literal {port}; "quoted" = value', $values['prefix']);
            $this->assertSame($parentPrecision, \ini_get('precision'));
        } finally {
            $application->release();
            $runtime->close();
        }
    }

    public function testManagedServerUsesPhpConfigurationAlongsideItsEnvironment(): void
    {
        $php = (new PhpServerConfig())->withIniSettings(['precision' => 8]);
        $manager = new ServerManager(appEnvironment: 'test', phpServer: $php);
        $server = $manager->start($this->directory, 'mysql://unused/test', $this->directory.'/runtime');

        try {
            $values = HttpClient::create()->request('GET', 'http://127.0.0.1:'.$server->port.'/settings')->toArray();
            $this->assertSame('8', $values['precision']);
            $this->assertSame('test', $values['app_env']);
            $this->assertSame('mysql://unused/test', $values['database_url']);
        } finally {
            $server->stop();
        }
    }

    public function testOpcacheIsReusedWithinOneServerAndIsolatedBetweenServers(): void
    {
        $this->requireOpcache();
        $file = $this->directory.'/public/index.php';
        touch($file, time() - 10);
        $config = WebServerConfig::php($this->directory)->withPhpServer((new PhpServerConfig())->withOpcache());
        $manager = new WebServerManager();
        $first = $manager->start($config);

        try {
            $client = HttpClient::create();
            $before = $client->request('GET', $first->baseUri.'/settings')->toArray();
            $after = $client->request('GET', $first->baseUri.'/settings')->toArray();
            $this->assertTrue($before['opcache']['opcache_enabled']);
            $this->assertSame($before['pid'], $after['pid']);
            $this->assertGreaterThan($before['opcache']['opcache_statistics']['hits'], $after['opcache']['opcache_statistics']['hits']);
            $second = $manager->start($config);

            try {
                $other = $client->request('GET', $second->baseUri.'/settings')->toArray();
                $this->assertTrue($other['opcache']['opcache_enabled']);
                $this->assertNotSame($before['pid'], $other['pid']);
                $this->assertLessThan($after['opcache']['opcache_statistics']['hits'], $other['opcache']['opcache_statistics']['hits']);

                if ('Windows' === PHP_OS_FAMILY) {
                    $this->assertNotSame($before['cache_id'], $other['cache_id']);
                }
            } finally {
                $second->stop();
            }
        } finally {
            $first->stop();
        }
    }

    public function testOpcacheRevalidatesChangedFilesBetweenRequests(): void
    {
        $this->requireOpcache();
        $file = $this->directory.'/public/index.php';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($file, '<?php echo "before";');
        touch($file, time() - 10);
        $server = (new WebServerManager())->start(WebServerConfig::php($this->directory)->withPhpServer((new PhpServerConfig())->withOpcache()));

        try {
            $client = HttpClient::create();
            $this->assertSame('before', $client->request('GET', $server->baseUri)->getContent());
            $this->assertSame('before', $client->request('GET', $server->baseUri)->getContent());
            $filesystem->dumpFile($file, '<?php echo "after";');
            touch($file, time() - 5);
            $this->assertSame('after', $client->request('GET', $server->baseUri)->getContent());
        } finally {
            $server->stop();
        }
    }

    private function requireOpcache(): void
    {
        if (!\extension_loaded('Zend OPcache')) {
            $this->markTestSkipped('The OPcache extension must be installed to test its cache behavior.');
        }
    }
}
