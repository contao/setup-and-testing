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
use Contao\E2eTesting\Application\ApplicationRuntime;
use Contao\E2eTesting\Application\HttpApplicationTrait;
use Contao\E2eTesting\Browser\BackendBrowser;
use Contao\E2eTesting\Browser\BrowserOptions;
use Contao\E2eTesting\Browser\BrowserRuntime;
use Contao\E2eTesting\Browser\BrowserSession;
use Contao\E2eTesting\Browser\BrowserType;
use Contao\E2eTesting\Database\DatabaseManager;
use Contao\E2eTesting\Database\DatabaseResetMode;
use Contao\E2eTesting\Http\ServerManager;
use Contao\E2eTesting\Http\ServerProcess;
use Contao\InstallationRecipe\Fixture\FixtureResult;
use Contao\InstallationRecipe\Fixture\FixtureSet;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class ManagedEdition implements ApplicationInterface
{
    use HttpApplicationTrait;

    private ServerProcess|null $server = null;

    private string|null $preparedFixtureFingerprint = null;

    /**
     * @var \WeakReference<FixtureResult>|null
     */
    private \WeakReference|null $preparedFixtureResult = null;

    public function __construct(
        private readonly ManagedEditionState $state,
        private readonly ServerManager $serverManager,
        private readonly BrowserRuntime $browserRuntime,
        private readonly ApplicationRuntime $runtime,
    ) {
    }

    public function __destruct()
    {
        $this->release();
    }

    public function runtime(): ApplicationRuntime
    {
        return $this->runtime;
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

        if ($fingerprint === $this->preparedFixtureFingerprint && $this->hasPreparedFixtures()) {
            $this->resetRuntime();

            return $this->database()->fixtures();
        }

        $result = $this->resetDatabase($fixtures);
        $this->preparedFixtureFingerprint = $fingerprint;
        $this->preparedFixtureResult = \WeakReference::create($result);

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

    public function uri(string $path = '/'): string
    {
        $this->startServer();
        $server = $this->server ?? throw new \LogicException('The E2E web server did not start.');
        $path = str_starts_with($path, '/') ? $path : '/'.$path;

        return 'http://localhost:'.$server->port.$path;
    }

    public function createBrowser(BrowserType $type = BrowserType::Firefox, BrowserOptions|null $options = null): BrowserSession
    {
        return $this->browserRuntime->createBrowser(rtrim($this->uri(), '/'), $type, $options);
    }

    public function createBackendBrowser(BrowserType $type = BrowserType::Firefox, BrowserOptions|null $options = null): BackendBrowser
    {
        return new BackendBrowser($this->createBrowser($type, $options));
    }

    public function browserRuntime(): BrowserRuntime
    {
        return $this->browserRuntime;
    }

    public function release(): void
    {
        try {
            $this->browserRuntime->reset();
        } finally {
            try {
                $this->releaseServer();
            } finally {
                $this->releaseInstallation();
            }
        }
    }

    private function releaseServer(): void
    {
        try {
            $this->server?->stop();
        } finally {
            $this->server = null;
        }
    }

    private function releaseInstallation(): void
    {
        try {
            $this->database()->close();
        } finally {
            $this->state->installation->lease->release();
        }
    }

    private function hasPreparedFixtures(): bool
    {
        return $this->database()->hasFixtures() && $this->preparedFixtureResult?->get() === $this->database()->fixtures();
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
