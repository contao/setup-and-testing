<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Browser;

use Playwright\Page\PageInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class BrowserRuntime
{
    /**
     * @var list<BrowserSession>
     */
    private array $sessions = [];

    private BrowserSession|null $currentBrowser = null;

    public function __construct(
        private readonly string $traceDirectory,
        private readonly BrowserSessionFactoryInterface $sessionFactory,
    ) {
    }

    public function createBrowser(string $baseUri, BrowserType $type = BrowserType::Firefox, BrowserOptions|null $options = null): BrowserSession
    {
        $browser = $this->sessionFactory->create($type, $baseUri, $options ?? BrowserOptions::create());
        $this->sessions[] = $browser;
        $this->currentBrowser = $browser;

        return $browser;
    }

    public function currentPage(): PageInterface
    {
        if (!$this->currentBrowser) {
            throw new \LogicException('Create a Playwright browser before using selector assertions.');
        }

        return $this->currentBrowser->page();
    }

    /**
     * Finishes the traces of all open browser sessions and writes them to traces.
     *
     * @return list<string>
     */
    public function finishTracing(string $name): array
    {
        if (!$this->sessions) {
            return [];
        }

        $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), '-');
        $paths = [];
        (new Filesystem())->mkdir($this->traceDirectory);

        foreach ($this->sessions as $i => $session) {
            $paths[] = $path = Path::join($this->traceDirectory, $name.($i ? '-'.($i + 1) : '').'.zip');
            $session->context()->tracing()->stop(['path' => $path]);
        }

        return $paths;
    }

    public function reset(): void
    {
        foreach ($this->sessions as $browser) {
            $browser->close();
        }

        $this->sessions = [];
        $this->currentBrowser = null;
    }

    public function close(): void
    {
        $this->reset();
        $this->sessionFactory->close();
    }
}
