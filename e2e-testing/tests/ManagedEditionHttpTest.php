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
use Contao\E2eTesting\Application\ApplicationRuntime;
use Contao\E2eTesting\Cache\FingerprintSet;
use Contao\E2eTesting\Database\DatabaseManager;
use Contao\E2eTesting\Database\DatabaseResetter;
use Contao\E2eTesting\Database\DatabaseServerConfig;
use Contao\E2eTesting\Http\HttpRequest;
use Contao\E2eTesting\Http\ServerManager;
use Contao\E2eTesting\Installation\InstallationLease;
use Contao\E2eTesting\Installation\PreparedInstallation;
use Contao\E2eTesting\ManagedEdition\ManagedEdition;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\E2eTesting\ManagedEdition\ManagedEditionState;
use Contao\E2eTesting\Process\ContaoConsole;
use Contao\E2eTesting\Process\ProcessRunner;
use Contao\InstallationRecipe\Cache\InMemoryCache;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Fixture\FixtureLoader;
use Contao\InstallationRecipe\Fixture\FixtureParser;
use Contao\InstallationRecipe\Fixture\FixtureValueResolver;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ManagedEditionHttpTest extends TestCase
{
    private string $directory;

    private ManagedEdition $application;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/e2e-http-'.bin2hex(random_bytes(6));
        (new Filesystem())->dumpFile($this->directory.'/installation/project/public/index.php', <<<'PHP'
            <?php
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode([
                'method' => $_SERVER['REQUEST_METHOD'],
                'path' => $_SERVER['REQUEST_URI'],
                'host' => $_SERVER['HTTP_HOST'],
                'https' => $_SERVER['HTTPS'] ?? null,
                'accept' => $_SERVER['HTTP_ACCEPT'] ?? null,
                'content-type' => $_SERVER['CONTENT_TYPE'] ?? null,
                'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
                'body' => file_get_contents('php://input'),
            ]);
            PHP);
        $this->application = $this->application();
    }

    protected function tearDown(): void
    {
        $this->application->release();
        (new Filesystem())->remove($this->directory);
    }

    public function testSendsJsonWithTheMethodHeadersBodyAndHost(): void
    {
        $request = HttpRequest::json('POST', '/api/example?test=1')
            ->withHeaders(['Authorization' => 'Bearer e2e', 'Host' => 'example.test'])
            ->withJson(['title' => 'Example'])
        ;
        $response = $this->application->send($request);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            [
                'method' => 'POST',
                'path' => '/api/example?test=1',
                'host' => 'example.test',
                'https' => null,
                'accept' => 'application/json',
                'content-type' => 'application/json',
                'authorization' => 'Bearer e2e',
                'body' => '{"title":"Example"}',
            ],
            $response->toArray(false),
        );
    }

    #[DataProvider('requestsWithoutBodies')]
    public function testJsonWithoutABodyDoesNotSendAJsonContentType(string $method): void
    {
        $response = $this->application->send(HttpRequest::json($method, '/api/example'));
        $values = $response->toArray(false);
        $this->assertSame($method, $values['method']);
        $this->assertSame('application/json', $values['accept']);
        if ('POST' === $method) {
            $this->assertSame('application/x-www-form-urlencoded', $values['content-type']);
        } else {
            $this->assertNull($values['content-type']);
        }
        $this->assertSame('', $values['body']);
    }

    public static function requestsWithoutBodies(): iterable
    {
        yield ['GET'];
        yield ['POST'];
        yield ['DELETE'];
    }

    public function testSendsRawBodiesAndCustomMediaTypes(): void
    {
        $request = HttpRequest::create('PATCH', '/api/example')
            ->withHeaders(['Content-Type' => 'text/plain', 'Accept' => 'text/plain'])
            ->withBody('raw body')
        ;
        $values = $this->application->send($request)->toArray(false);
        $this->assertSame('PATCH', $values['method']);
        $this->assertSame('text/plain', $values['content-type']);
        $this->assertSame('text/plain', $values['accept']);
        $this->assertSame('raw body', $values['body']);
    }

    public function testRequestsAndBrowserKitUseTheLocalServerUrl(): void
    {
        $values = $this->application->send(HttpRequest::json('GET', '/default'))->toArray(false);
        $this->assertSame('/default', $values['path']);
        $this->assertStringStartsWith('localhost:', $values['host']);
        $this->assertNull($values['https']);
        $this->assertSame('GET', $this->application->send(HttpRequest::get('/default'))->toArray(false)['method']);
        $browser = $this->application->createHttpBrowser();
        $browser->request('GET', '/default');
        $this->assertSame(422, $browser->getInternalResponse()->getStatusCode());
    }

    public function testSharesRuntimeCacheWithRegularApplications(): void
    {
        $runtime = $this->application->runtime();
        $other = $runtime->createApplication(ApplicationConfig::create('http://localhost:8080'));

        try {
            $runtime->cache->set('custom.value', 'shared');

            $this->assertSame($runtime, $other->runtime());
            $this->assertSame('shared', $other->runtime()->cache->get('custom.value'));
        } finally {
            $other->release();
        }
    }

    private function application(): ManagedEdition
    {
        $installation = new PreparedInstallation(
            new InstallationLease($this->directory.'/installation', 0, null),
            new DatabaseManager(new DatabaseServerConfig('mysql://localhost'), 'unused', $this->fixtureLoader(), new DatabaseResetter()),
            new FingerprintSet('http-test', 'http-test', 'http-test'),
        );
        $recipe = InstallationRecipe::create(ComposerConfig::managedEdition('^5.7'));

        $runtime = ApplicationRuntime::create();

        return new ManagedEdition(
            new ManagedEditionState(
                $installation,
                ManagedEditionConfig::create($recipe, $this->directory),
                new ContaoConsole(new ProcessRunner()),
            ),
            new ServerManager(),
            $runtime->createBrowserRuntime($this->directory.'/traces'),
            $runtime,
        );
    }

    private function fixtureLoader(): FixtureLoader
    {
        $cache = new InMemoryCache();

        return new FixtureLoader(new FixtureParser($cache), new FixtureValueResolver(), $cache);
    }
}
