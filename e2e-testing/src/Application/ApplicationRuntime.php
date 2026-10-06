<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Application;

use Contao\E2eTesting\Browser\BrowserOptionsNormalizer;
use Contao\E2eTesting\Browser\BrowserRuntime;
use Contao\E2eTesting\Browser\BrowserSessionFactoryInterface;
use Contao\E2eTesting\Browser\PlaywrightClientFactory;
use Contao\E2eTesting\Browser\PlaywrightManager;
use Contao\InstallationRecipe\Cache\InMemoryCache;

final class ApplicationRuntime
{
    private static self|null $shared = null;

    private bool $closed = false;

    public function __construct(
        public readonly InMemoryCache $cache,
        private readonly BrowserSessionFactoryInterface $browserSessionFactory,
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    public static function create(): self
    {
        return new self(new InMemoryCache(), new PlaywrightManager(new BrowserOptionsNormalizer(), new PlaywrightClientFactory()));
    }

    public static function shared(): self
    {
        return self::$shared ??= self::create();
    }

    public function createApplication(ApplicationConfigInterface $config): ApplicationInterface
    {
        $this->ensureOpen();

        return $config->createApplication($this);
    }

    public function createBrowserRuntime(string $traceDirectory): BrowserRuntime
    {
        $this->ensureOpen();

        return new BrowserRuntime($traceDirectory, $this->browserSessionFactory);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->browserSessionFactory->close();
    }

    private function ensureOpen(): void
    {
        if ($this->closed) {
            throw new \LogicException('The application runtime has been closed.');
        }
    }
}
