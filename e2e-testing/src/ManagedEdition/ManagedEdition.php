<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\ManagedEdition;

use Contao\E2eTesting\Application\ApplicationInterface;
use Contao\E2eTesting\Browser\BackendBrowser;
use Contao\E2eTesting\Browser\BrowserOptions;
use Contao\E2eTesting\Browser\BrowserRuntime;
use Contao\E2eTesting\Browser\BrowserSession;
use Contao\E2eTesting\Browser\BrowserType;
use Contao\E2eTesting\Browser\PlaywrightManager;
use Contao\E2eTesting\Database\DatabaseManager;
use Contao\E2eTesting\Database\DatabaseResetMode;
use Contao\E2eTesting\Http\HttpRequest;
use Contao\E2eTesting\Http\Origin;
use Contao\E2eTesting\Http\ServerManager;
use Contao\E2eTesting\Http\ServerProcess;
use Contao\InstallationRecipe\Fixture\FixtureResult;
use Contao\InstallationRecipe\Fixture\FixtureSet;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ManagedEdition implements ApplicationInterface
{
    private ServerProcess|null $server = null;

    private readonly BrowserRuntime $browserRuntime;

    private string|null $preparedFixtureFingerprint = null;

    private FixtureResult|null $preparedFixtureResult = null;

    public function __construct(
        private readonly ManagedEditionState $state,
        private readonly ServerManager $serverManager = new ServerManager(),
        PlaywrightManager $playwrightManager = new PlaywrightManager(),
    ) {
        $this->browserRuntime = new BrowserRuntime(
            Path::join($this->state->config->environment->cache->rootDirectory, 'traces'),
            $playwrightManager,
        );
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

    public function resetState(): void
    {
        $this->resetDatabase();
    }

    public function resetRuntime(): void
    {
        $this->browserRuntime->reset();
        $this->clearMutableRuntime();
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

    public function createBrowser(BrowserType $type = BrowserType::Firefox, BrowserOptions|null $options = null, Origin|null $origin = null): BrowserSession
    {
        return $this->browserRuntime->createBrowser($this->browserUri($origin), $type, $options);
    }

    public function createBackendBrowser(BrowserType $type = BrowserType::Firefox, BrowserOptions|null $options = null, Origin|null $origin = null): BackendBrowser
    {
        return new BackendBrowser($this->createBrowser($type, $options, $origin));
    }

    public function browserRuntime(): BrowserRuntime
    {
        return $this->browserRuntime;
    }

    public function release(): void
    {
        $this->browserRuntime->close();
        $this->server?->stop();
        $this->server = null;
        $this->database()->close();
        $this->state->installation->lease->release();
    }

    private function registerOrigin(Origin $origin): string
    {
        $alias = 'contao-e2e-'.substr(hash('sha256', $origin->host."\0".(int) $origin->https), 0, 16);
        $mapping = json_decode((string) file_get_contents($this->server->mappingFile), true, 512, JSON_THROW_ON_ERROR);
        $mapping[$alias.'.localhost'] = ['host' => $origin->host, 'https' => $origin->https];
        (new Filesystem())->dumpFile($this->server->mappingFile, json_encode($mapping, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $alias;
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
