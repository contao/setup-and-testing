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

use Contao\E2eTesting\Application\ApplicationConfig;
use Contao\E2eTesting\Application\ApplicationInterface;
use Contao\E2eTesting\Application\LocalApplicationConfig;
use Contao\E2eTesting\Http\HttpRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ApplicationHttpTest extends TestCase
{
    private string $directory;

    private ApplicationInterface $application;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/application-http-'.bin2hex(random_bytes(6));
        (new Filesystem())->dumpFile($this->directory.'/public/index.php', <<<'PHP'
            <?php
            if ('/redirect' === $_SERVER['REQUEST_URI']) {
                header('Location: /destination', true, 302);
                exit;
            }
            setcookie('example', 'cookie');
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode([
                'method' => $_SERVER['REQUEST_METHOD'],
                'path' => $_SERVER['REQUEST_URI'],
                'host' => $_SERVER['HTTP_HOST'],
                'origin' => $_SERVER['HTTP_ORIGIN'] ?? null,
                'https' => $_SERVER['HTTPS'] ?? null,
                'accept' => $_SERVER['HTTP_ACCEPT'] ?? null,
                'content-type' => $_SERVER['CONTENT_TYPE'] ?? null,
                'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
                'cookie' => $_COOKIE['example'] ?? null,
                'body' => file_get_contents('php://input'),
            ]);
            PHP);
        $this->application = LocalApplicationConfig::php($this->directory)->createApplication();
    }

    protected function tearDown(): void
    {
        $this->application->release();
        (new Filesystem())->remove($this->directory);
    }

    public function testJsonUsesTheApplicationUrlByDefault(): void
    {
        $request = HttpRequest::json('POST', '/api/example?test=1')
            ->withHeader('Authorization', 'Bearer e2e')
            ->withJson(['title' => 'Example'])
        ;
        $response = $this->application->send($request);
        $values = $response->toArray(false);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('POST', $values['method']);
        $this->assertSame('/api/example?test=1', $values['path']);
        $this->assertSame('application/json', $values['accept']);
        $this->assertSame('application/json', $values['content-type']);
        $this->assertSame('Bearer e2e', $values['authorization']);
        $this->assertSame('{"title":"Example"}', $values['body']);
        $this->assertNull($values['https']);
    }

    public function testExistingApplicationUrlPreservesItsSubdirectory(): void
    {
        $application = ApplicationConfig::create($this->application->uri().'app')->createApplication();

        try {
            $values = $application->send(HttpRequest::get('/endpoint?test=1'))->toArray(false);
            $this->assertSame('/app/endpoint?test=1', $values['path']);
            $browser = $application->createHttpBrowser();
            $browser->request('GET', $application->uri('/html'));
            $values = json_decode($browser->getInternalResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('/app/html', $values['path']);
        } finally {
            $application->release();
        }
    }

    public function testRequestPreservesDoubleSlashPaths(): void
    {
        $values = $this->application->send(HttpRequest::get('//'))->toArray(false);
        $this->assertSame('//', $values['path']);
    }

    public function testHostAndOriginHeadersAreForwardedWithoutChangingTheConnection(): void
    {
        $request = HttpRequest::get('/endpoint')
            ->withHeaders(['Host' => 'example.test', 'Origin' => 'https://caller.example'])
        ;
        $values = $this->application->send($request)->toArray(false);
        $this->assertSame('example.test', $values['host']);
        $this->assertSame('https://caller.example', $values['origin']);
        $this->assertNull($values['https']);
        $values = $this->application->send(HttpRequest::get('/endpoint'))->toArray(false);
        $this->assertStringStartsWith('127.0.0.1:', $values['host']);
        $this->assertNull($values['origin']);
        $this->assertNull($values['https']);
    }

    public function testBrowserKitUsesTheApplicationUrlAndMaintainsCookies(): void
    {
        $browser = $this->application->createHttpBrowser();
        $browser->request('GET', '/endpoint');
        $browser->request('GET', '/second');

        $values = json_decode($browser->getInternalResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('/second', $values['path']);
        $this->assertSame('cookie', $values['cookie']);
        $this->assertSame(422, $browser->getInternalResponse()->getStatusCode());
    }

    public function testBrowserKitPreservesEncodedUrlCredentialsAcrossRequests(): void
    {
        (new Filesystem())->dumpFile($this->directory.'/public/protected.php', <<<'PHP'
            <?php
            $expected = 'Basic '.base64_encode('test+user:p@ss:word');
            http_response_code($expected === ($_SERVER['HTTP_AUTHORIZATION'] ?? null) ? 200 : 401);
            echo 'protected';
            PHP);
        $uri = str_replace('http://', 'http://test%2Buser:p%40ss%3Aword@', $this->application->uri());
        $application = ApplicationConfig::create($uri)->createApplication();

        try {
            $this->assertSame(200, $application->send(HttpRequest::get('/protected.php'))->getStatusCode());
            $browser = $application->createHttpBrowser();

            foreach (['/protected.php', '/protected.php?second=1'] as $path) {
                $browser->request('GET', $path);
                $this->assertSame(200, $browser->getInternalResponse()->getStatusCode());
            }
            $browser->request('GET', '/endpoint', server: ['HTTP_AUTHORIZATION' => 'Bearer override']);
            $values = json_decode($browser->getInternalResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('Bearer override', $values['authorization']);
        } finally {
            $application->release();
        }
    }

    public function testBrowserKitDoesNotSendUrlCredentialsToAnotherServer(): void
    {
        $uri = str_replace('http://', 'http://test-user:test-password@', $this->application->uri());
        $application = ApplicationConfig::create($uri)->createApplication();
        $other = LocalApplicationConfig::php($this->directory)->createApplication();

        try {
            $browser = $application->createHttpBrowser();
            $browser->request('GET', '/endpoint');
            $values = json_decode($browser->getInternalResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('Basic '.base64_encode('test-user:test-password'), $values['authorization']);
            $browser->request('GET', $other->uri('/endpoint'));
            $values = json_decode($browser->getInternalResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNull($values['authorization']);
            $this->assertSame(parse_url($other->uri(), PHP_URL_HOST).':'.parse_url($other->uri(), PHP_URL_PORT), $values['host']);
            $browser->request('GET', '/relative');
            $this->assertSame($other->uri('/relative'), $browser->getInternalRequest()->getUri());
        } finally {
            $other->release();
            $application->release();
        }
    }

    public function testBrowserKitSupportsTheHostHeader(): void
    {
        $browser = $this->application->createHttpBrowser();
        $browser->request('GET', $this->application->uri('/endpoint'), server: ['HTTP_HOST' => 'example.test']);

        $values = json_decode($browser->getInternalResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('example.test', $values['host']);
        $this->assertNull($values['https']);
    }

    public function testRedirectsAreNotFollowedByEitherHttpClient(): void
    {
        $this->assertSame(302, $this->application->send(HttpRequest::get('/redirect'))->getStatusCode());
        $browser = $this->application->createHttpBrowser();
        $browser->request('GET', '/redirect');
        $this->assertSame(302, $browser->getInternalResponse()->getStatusCode());
    }

    public function testCustomRoutersReceiveTheHostHeader(): void
    {
        (new Filesystem())->dumpFile($this->directory.'/router.php', '<?php echo $_SERVER["HTTP_HOST"];');
        $application = LocalApplicationConfig::php($this->directory, router: 'router.php')->createApplication();

        try {
            $response = $application->send(HttpRequest::get('/')->withHeader('Host', 'example.test'));
            $this->assertSame('example.test', $response->getContent());
        } finally {
            $application->release();
        }
    }
}
