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

use Composer\Autoload\ClassLoader;
use Contao\E2eTesting\Application\ApplicationConfig;
use Contao\E2eTesting\Application\LocalApplicationConfig;
use Contao\E2eTesting\Browser\BrowserRuntime;
use Contao\E2eTesting\Browser\PlaywrightManager;
use Contao\E2eTesting\Cache\FingerprintSet;
use Contao\E2eTesting\Database\DatabaseManager;
use Contao\E2eTesting\Database\DatabaseServerConfig;
use Contao\E2eTesting\Http\HttpBrowserOptions;
use Contao\E2eTesting\Http\HttpRequest;
use Contao\E2eTesting\Http\ServerManager;
use Contao\E2eTesting\Installation\ApplicationPreparer;
use Contao\E2eTesting\Installation\InstallationLease;
use Contao\E2eTesting\Installation\PreparedInstallation;
use Contao\E2eTesting\ManagedEdition\ManagedEdition;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\E2eTesting\ManagedEdition\ManagedEditionState;
use Contao\E2eTesting\Process\ContaoConsole;
use Contao\E2eTesting\Process\ProcessRunner;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Fixture\FixtureLoader;
use Contao\InstallationRecipe\Fixture\FixtureParser;
use Contao\InstallationRecipe\Fixture\FixtureValueResolver;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SimulatedOriginTest extends TestCase
{
    private string $directory;

    private ManagedEdition $application;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/simulated-origin-'.bin2hex(random_bytes(6));
        $this->application = $this->application(true);
    }

    protected function tearDown(): void
    {
        $this->application->release();
        (new Filesystem())->remove($this->directory);
    }

    /**
     * @param array{string, string, int} $expected
     */
    #[DataProvider('origins')]
    public function testRequestsSimulateOriginsWithoutChangingTheConnection(string $origin, array $expected): void
    {
        $values = $this->application->send(HttpRequest::get('//?test=1')->withSimulatedOrigin($origin))->toArray();
        $this->assertSame($expected, [$values['scheme'], $values['host'], $values['port']]);
        $this->assertSame('//?test=1', $values['uri']);
        $this->assertStringStartsWith('localhost:', $values['raw-host']);
        $this->assertNull($values['raw-https']);
        $plain = $this->application->send(HttpRequest::get('/'))->toArray();
        $this->assertSame('http', $plain['scheme']);
        $this->assertSame('localhost', $plain['host']);
    }

    public static function origins(): iterable
    {
        yield 'HTTPS' => ['https://example.local', ['https', 'example.local', 443]];
        yield 'HTTP' => ['http://other.local', ['http', 'other.local', 80]];
        yield 'custom port' => ['https://example.local:8443', ['https', 'example.local', 8443]];
    }

    public function testBrowserKitCanChooseDifferentOriginsAndKeepDoubleSlashPaths(): void
    {
        foreach (['https://example.local', 'http://other.local:8080'] as $origin) {
            $browser = $this->application->createHttpBrowser(HttpBrowserOptions::create()->withSimulatedOrigin($origin));
            $browser->request('GET', '//');
            $values = json_decode($browser->getInternalResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('//', $values['uri']);
            $this->assertSame(parse_url($origin, PHP_URL_HOST), $values['host']);
            $this->assertSame(parse_url($origin, PHP_URL_SCHEME), $values['scheme']);
            $browser->request('GET', '//second?test=1');
            $values = json_decode($browser->getInternalResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('//second?test=1', $values['uri']);
            $this->assertSame(parse_url($origin, PHP_URL_HOST), $values['host']);
        }
    }

    #[DataProvider('publicRedirects')]
    public function testPublicRedirectsRemainVisibleAndCanBeFollowedLocally(string $path, string $location): void
    {
        $response = $this->application->send(HttpRequest::get($path)->withSimulatedOrigin('https://example.local'));
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([$location], $response->getHeaders(false)['location']);
        $browser = $this->application->createHttpBrowser(HttpBrowserOptions::create()->withSimulatedOrigin('https://example.local'));
        $browser->request('GET', $path);
        $this->assertSame($location, $browser->getInternalResponse()->getHeader('Location'));
        $this->assertSame(302, $browser->getInternalResponse()->getStatusCode());
        $browser->followRedirect();
        $values = json_decode($browser->getInternalResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('//destination?test=1', $values['uri']);
        $this->assertSame('https', $values['scheme']);
        $this->assertSame('example.local', $values['host']);
        $this->assertStringStartsWith($this->application->uri(), $browser->getInternalRequest()->getUri());
    }

    public static function publicRedirects(): iterable
    {
        yield 'absolute' => ['/redirect', 'https://example.local//destination?test=1'];
        yield 'protocol relative' => ['/protocol-redirect', '//example.local//destination?test=1'];
    }

    public function testBrowserKitMapsEquivalentPublicUrlsWithoutNormalizingThePath(): void
    {
        $browser = $this->application->createHttpBrowser(HttpBrowserOptions::create()->withSimulatedOrigin('https://example.local'));
        $browser->request('GET', 'https://EXAMPLE.local:443//path?test=1');

        $values = json_decode($browser->getInternalResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('//path?test=1', $values['uri']);
        $this->assertSame('https', $values['scheme']);
        $this->assertSame('example.local', $values['host']);
    }

    public function testDisabledManagedInstallationsDoNotTrustSimulatedOrigins(): void
    {
        $this->application->release();
        $this->application = $this->application(false);

        $values = $this->application->send(HttpRequest::get('/')->withSimulatedOrigin('https://example.local'))->toArray();
        $this->assertSame('http', $values['scheme']);
        $this->assertSame('localhost', $values['host']);
        $this->assertFileDoesNotExist($this->directory.'/installation/project/config/config.yaml');
        $browser = $this->application->createHttpBrowser(HttpBrowserOptions::create()->withSimulatedOrigin('https://example.local'));
        $browser->request('GET', '/');

        $values = json_decode($browser->getInternalResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('http', $values['scheme']);
        $this->assertSame('localhost', $values['host']);
    }

    public function testExternalApplicationConfigurationIsLeftToTheConsumer(): void
    {
        $this->application->release();
        $this->application = $this->application(false);
        $application = ApplicationConfig::create($this->application->uri())->createApplication();

        try {
            $browser = $application->createHttpBrowser(HttpBrowserOptions::create()->withSimulatedOrigin('https://example.local'));
            $browser->request('GET', '/');
            $values = json_decode($browser->getInternalResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('http', $values['scheme']);
            $this->assertSame('localhost', $values['host']);
            $this->assertFileDoesNotExist($this->directory.'/installation/project/config/config.yaml');
        } finally {
            $application->release();
        }
    }

    public function testBrowserKitDoesNotForwardSimulatedHeadersToAnotherServer(): void
    {
        $other = LocalApplicationConfig::php($this->directory.'/installation/project')->createApplication();

        try {
            $browser = $this->application->createHttpBrowser(HttpBrowserOptions::create()->withSimulatedOrigin('https://example.local'));
            $browser->request('GET', $other->uri('/'));
            $values = json_decode($browser->getInternalResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('http', $values['scheme']);
            $this->assertSame('127.0.0.1', $values['host']);
            $this->assertSame((int) parse_url($other->uri(), PHP_URL_PORT), $values['port']);
            $browser->request('GET', $other->uri('/redirect'));
            $this->assertSame(302, $browser->getInternalResponse()->getStatusCode());
        } finally {
            $other->release();
        }
    }

    private function application(bool $enabled): ManagedEdition
    {
        $lease = new InstallationLease($this->directory.'/installation', 0, null);
        $installation = new PreparedInstallation(
            $lease,
            new DatabaseManager(new DatabaseServerConfig('mysql://localhost'), 'unused', $this->fixtureLoader()),
            new FingerprintSet('origin', 'origin', 'origin'),
        );
        $config = ManagedEditionConfig::create(InstallationRecipe::create(ComposerConfig::managedEdition('^5.7')), $this->directory);
        if ($enabled) {
            $config = $config->withSimulatedOrigins();
        }
        (new ApplicationPreparer())->prepare($config, $installation->directory(), null);
        $this->writeFrontController($installation->directory());

        return new ManagedEdition(
            new ManagedEditionState($installation, $config, new ContaoConsole(new ProcessRunner())),
            new ServerManager(),
            new BrowserRuntime($this->directory.'/traces', new PlaywrightManager()),
        );
    }

    private function writeFrontController(string $directory): void
    {
        $loader = (new \ReflectionClass(ClassLoader::class))->getFileName();
        $this->assertIsString($loader);
        $autoload = var_export(\dirname($loader, 2).'/autoload.php', true);
        $source = <<<'PHP'
            use Symfony\Component\HttpFoundation\Request;
            use Symfony\Component\Yaml\Yaml;

            $config = dirname(__DIR__).'/config/config.yaml';
            foreach (is_file($config) ? Yaml::parseFile($config)['imports'] : [] as $import) {
                $framework = Yaml::parseFile(dirname($config).'/'.$import['resource'])['framework'] ?? [];
                $headers = 0;
                foreach ($framework['trusted_headers'] ?? [] as $name) {
                    $headers |= constant(Request::class.'::HEADER_'.strtoupper(str_replace('-', '_', $name)));
                }
                Request::setTrustedProxies($framework['trusted_proxies'] ?? [], $headers);
            }
            $request = Request::createFromGlobals();
            if (in_array($request->getRequestUri(), ['/redirect', '/protocol-redirect'], true)) {
                $origin = '/protocol-redirect' === $request->getRequestUri() ? '//'.$request->getHttpHost() : $request->getSchemeAndHttpHost();
                header('Location: '.$origin.'//destination?test=1', true, 302);
                exit;
            }
            header('Content-Type: application/json');
            echo json_encode([
                'uri' => $request->getRequestUri(),
                'scheme' => $request->getScheme(),
                'host' => $request->getHost(),
                'port' => $request->getPort(),
                'raw-host' => $_SERVER['HTTP_HOST'],
                'raw-https' => $_SERVER['HTTPS'] ?? null,
            ]);
            PHP;
        (new Filesystem())->dumpFile($directory.'/public/index.php', '<?php require '.$autoload.';'.$source);
    }

    private function fixtureLoader(): FixtureLoader
    {
        return new FixtureLoader(new FixtureParser(), new FixtureValueResolver());
    }
}
