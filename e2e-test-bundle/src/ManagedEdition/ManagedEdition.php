<?php

declare(strict_types=1);

namespace Contao\E2eTestBundle\ManagedEdition;

use Contao\E2eTestBundle\Browser\BackendBrowser;
use Contao\E2eTestBundle\Browser\BrowserOptions;
use Contao\E2eTestBundle\Browser\BrowserSessionResetter;
use Contao\E2eTestBundle\Browser\PantherClientFactory;
use Contao\E2eTestBundle\Database\DatabaseManager;
use Contao\E2eTestBundle\Database\DatabaseResetMode;
use Contao\E2eTestBundle\Http\HttpRequest;
use Contao\E2eTestBundle\Http\Origin;
use Contao\E2eTestBundle\Http\ServerManager;
use Contao\E2eTestBundle\Http\ServerProcess;
use Contao\InstallationRecipe\Fixture\FixtureResult;
use Contao\InstallationRecipe\Fixture\FixtureSet;
use Facebook\WebDriver\Exception\WebDriverException;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Panther\Client;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ManagedEdition
{
    private ServerProcess|null $server = null;

    /**
     * @var list<array{client: Client, key: string}>
     */
    private array $clients = [];

    /**
     * @var array<string, list<Client>>
     */
    private array $reusableClients = [];

    private Client|null $currentClient = null;

    private string|null $preparedFixtureFingerprint = null;

    private FixtureResult|null $preparedFixtureResult = null;

    private readonly BrowserSessionResetter $browserSessionResetter;

    public function __construct(
        private readonly ManagedEditionState $state,
        private readonly ServerManager $serverManager = new ServerManager(),
        private readonly PantherClientFactory $pantherClientFactory = new PantherClientFactory(),
    ) {
        $this->browserSessionResetter = new BrowserSessionResetter();
    }

    public function __destruct()
    {
        $this->release();
    }

    public function directory(): string
    {
        return $this->state->installation->directory();
    }

    public function database(): DatabaseManager
    {
        return $this->state->installation->database;
    }

    public function resetDatabase(FixtureSet|null $fixtures = null): FixtureResult
    {
        $this->preparedFixtureFingerprint = null;
        $this->preparedFixtureResult = null;
        $this->resetRuntime();
        $fixtures ??= $this->state->config->recipe->fixtures;

        if (DatabaseResetMode::RECREATE_SCHEMA === $this->state->config->resetMode) {
            $this->database()->recreate();
            $this->state->console->migrate($this->directory(), $this->database()->applicationUrl());
        }

        return $this->database()->reset($fixtures);
    }

    public function prepareDatabase(FixtureSet $fixtures): FixtureResult
    {
        $fingerprint = $this->fixtureFingerprint($fixtures);

        if ($fingerprint === $this->preparedFixtureFingerprint && $this->preparedFixtureResult) {
            $this->resetRuntime();

            return $this->preparedFixtureResult;
        }

        $result = $this->resetDatabase($fixtures);
        $this->preparedFixtureFingerprint = $fingerprint;
        $this->preparedFixtureResult = $result;

        return $result;
    }

    public function resetRuntime(): void
    {
        $this->resetRuntimeState();
    }

    public function synchronizeFiles(string ...$paths): void
    {
        $this->state->console->filesync($this->directory(), $this->database()->applicationUrl(), $paths);
    }

    public function startServer(): self
    {
        if ($this->server) {
            return $this;
        }

        $runtimeDirectory = Path::join(
            $this->state->config->environment->cache->rootDirectory,
            'runtime',
            $this->state->installation->fingerprints->dependency.'-'.$this->state->installation->lease->slot,
        );
        $this->server = $this->serverManager->start($this->directory(), $this->database()->applicationUrl(), $runtimeDirectory);

        return $this;
    }

    public function uri(Origin $origin, string $path = '/'): string
    {
        $this->startServer();
        $alias = $this->registerOrigin($origin);
        $path = str_starts_with($path, '/') ? $path : '/'.$path;

        return 'http://'.$alias.'.localhost:'.$this->server->port.$path;
    }

    public function request(string $method, string $path, Origin $origin): ResponseInterface
    {
        return HttpClient::create(['max_redirects' => 0])->request($method, $this->uri($origin, $path));
    }

    public function send(HttpRequest $request): ResponseInterface
    {
        return HttpClient::create(['max_redirects' => 0])->request(
            'GET',
            $this->uri($request->origin, $request->path),
            [
                'headers' => $request->headers,
            ],
        );
    }

    public function createHttpBrowser(Origin $origin): HttpBrowser
    {
        $transportUri = $this->uri($origin);
        $host = parse_url($transportUri, PHP_URL_HOST);
        $port = parse_url($transportUri, PHP_URL_PORT);

        if (!\is_string($host)) {
            throw new \LogicException('Could not determine the E2E web server host.');
        }

        $browser = new HttpBrowser(HttpClient::create(['max_redirects' => 0]));
        $browser->followRedirects(false);
        $browser->setServerParameter('HTTP_HOST', $host.(\is_int($port) ? ':'.$port : ''));

        return $browser;
    }

    public function createFirefoxClient(Origin|null $origin = null, BrowserOptions|null $options = null): Client
    {
        $options ??= BrowserOptions::create();
        $baseUri = $this->browserUri($origin);
        $create = fn () => $this->pantherClientFactory->createFirefox(
            $baseUri,
            $options,
            $this->state->config->environment->cache->rootDirectory,
        );

        return $this->reuseOrCreateClient($this->browserSessionKey('firefox', $baseUri, $options), $create);
    }

    public function createChromeClient(Origin|null $origin = null, BrowserOptions|null $options = null): Client
    {
        $options ??= BrowserOptions::create();
        $baseUri = $this->browserUri($origin);
        $create = fn () => $this->pantherClientFactory->createChrome(
            $baseUri,
            $options,
            $this->state->config->environment->cache->rootDirectory,
        );

        return $this->reuseOrCreateClient($this->browserSessionKey('chrome', $baseUri, $options), $create);
    }

    public function createFirefoxBackendBrowser(Origin|null $origin = null, BrowserOptions|null $options = null): BackendBrowser
    {
        return new BackendBrowser($this->createFirefoxClient($origin, $options));
    }

    public function createChromeBackendBrowser(Origin|null $origin = null, BrowserOptions|null $options = null): BackendBrowser
    {
        return new BackendBrowser($this->createChromeClient($origin, $options));
    }

    public function currentClient(): Client
    {
        if (!$this->currentClient) {
            throw new \LogicException('Create a Panther client before using selector assertions.');
        }

        return $this->currentClient;
    }

    public function release(): void
    {
        $this->quitClients();
        $this->quitReusableClients();
        $this->server?->stop();
        $this->server = null;
        $this->database()->close();
        $this->state->installation->lease->release();
    }

    private function resetRuntimeState(): void
    {
        $this->recycleClients();
        $this->clearMutableRuntime();
    }

    private function quitClients(): void
    {
        foreach ($this->clients as $entry) {
            $this->quitClient($entry['client']);
        }

        $this->clients = [];
        $this->currentClient = null;
    }

    private function recycleClients(): void
    {
        foreach ($this->clients as $entry) {
            $client = $entry['client'];

            if ($this->browserSessionResetter->reset($client)) {
                $this->reusableClients[$entry['key']][] = $client;
            } else {
                $this->quitClient($client);
            }
        }

        $this->clients = [];
        $this->currentClient = null;
    }

    private function quitReusableClients(): void
    {
        foreach ($this->reusableClients as $clients) {
            foreach ($clients as $client) {
                $this->quitClient($client);
            }
        }

        $this->reusableClients = [];
        $this->currentClient = null;
    }

    private function quitClient(Client $client): void
    {
        try {
            $client->quit();
        } catch (WebDriverException) {
            // The browser or driver process has already stopped.
        }
    }

    private function registerOrigin(Origin $origin): string
    {
        $alias = 'contao-e2e-'.substr(hash('sha256', $origin->host."\0".(int) $origin->https), 0, 16);
        $mapping = json_decode((string) file_get_contents($this->server->mappingFile), true, 512, JSON_THROW_ON_ERROR);
        $mapping[$alias.'.localhost'] = ['host' => $origin->host, 'https' => $origin->https];
        (new Filesystem())->dumpFile($this->server->mappingFile, json_encode($mapping, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $alias;
    }

    /**
     * @param \Closure(): Client $create
     */
    private function reuseOrCreateClient(string $key, \Closure $create): Client
    {
        $client = $this->takeReusableClient($key) ?? $create();
        $this->clients[] = ['client' => $client, 'key' => $key];
        $this->currentClient = $client;

        return $client;
    }

    private function takeReusableClient(string $key): Client|null
    {
        if (!isset($this->reusableClients[$key])) {
            return null;
        }

        $client = array_shift($this->reusableClients[$key]);

        if (!$this->reusableClients[$key]) {
            unset($this->reusableClients[$key]);
        }

        return $client;
    }

    private function browserSessionKey(string $browser, string $baseUri, BrowserOptions $options): string
    {
        return hash('sha256', implode("\0", [
            $browser,
            $baseUri,
            $options->sessionKey(),
        ]));
    }

    private function browserUri(Origin|null $origin): string
    {
        if ($origin) {
            return $this->uri($origin);
        }

        $this->startServer();
        $server = $this->server ?? throw new \LogicException('The E2E web server did not start.');

        return 'http://localhost:'.$server->port;
    }

    private function clearMutableRuntime(): void
    {
        $filesystem = new Filesystem();
        $filesystem->remove([
            Path::join($this->directory(), 'var/sessions'),
            Path::join($this->directory(), 'var/cache/prod/pools'),
        ]);
    }

    private function fixtureFingerprint(FixtureSet $fixtures): string
    {
        $hashes = [];

        foreach ($fixtures->files as $file) {
            $hashes[$file] = hash_file('sha256', $file);
        }

        return hash('sha256', serialize($hashes));
    }
}
