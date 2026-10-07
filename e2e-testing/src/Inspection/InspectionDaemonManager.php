<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Inspection;

use Composer\Autoload\ClassLoader;
use Contao\E2eTesting\Cache\CacheConfig;
use Contao\E2eTesting\Cache\WorkspaceInitializer;
use Symfony\Component\Filesystem\Filesystem;

final readonly class InspectionDaemonManager
{
    public function start(string $file, CacheConfig $cache): InspectionSessionStore
    {
        $file = realpath($file);

        if (false === $file || !is_file($file) || !is_readable($file)) {
            throw new \InvalidArgumentException('The inspection file must exist and be readable.');
        }

        (new WorkspaceInitializer())->initialize($cache);
        $store = InspectionSessionStore::forCache($cache);
        $store->initialize();

        $control = $this->controlLock($store);

        try {
            $this->startWorker($file, $store);
        } finally {
            unset($control);
        }

        return $store;
    }

    public function stop(InspectionSessionStore $store): bool
    {
        if (!is_dir($store->directory)) {
            return false;
        }

        $control = $this->controlLock($store);

        try {
            return $this->requestAndWait($store);
        } finally {
            unset($control);
        }
    }

    private function startWorker(string $file, InspectionSessionStore $store): void
    {
        if ($store->isActive()) {
            throw new \RuntimeException('An inspection session is already running. Use server:stop first.');
        }

        $token = bin2hex(random_bytes(24));
        $store->write(['token' => $token, 'pid' => 0, 'phase' => 'starting', 'file' => $file]);
        (new Filesystem())->dumpFile($store->logFile(), '');
        (new InspectionDaemonLauncher())->launch(
            [
                PHP_BINARY, \dirname(__DIR__, 2).'/bin/inspection-worker.php',
                $this->autoloadFile(), $store->directory, $token, $file,
            ],
            $store,
        );
        $this->waitForRegistration($store);
    }

    private function requestAndWait(InspectionSessionStore $store): bool
    {
        if (!$store->isActive()) {
            return false;
        }

        $state = $store->read();
        $store->requestStop((string) $state['token']);
        $this->interruptPreparation($state);
        $deadline = microtime(true) + 30;

        while ($store->isActive()) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Shutdown is still pending. Run server:status and retry server:stop once preparation finishes.');
            }

            usleep(100_000);
        }

        return true;
    }

    /**
     * @param array<string, int|string> $state
     */
    private function interruptPreparation(array $state): void
    {
        $pid = (int) ($state['pid'] ?? 0);

        if ($pid <= 1 || !($state['interruptible'] ?? 0) || !\function_exists('posix_kill') || !\defined('SIGWINCH')) {
            return;
        }

        // SIGWINCH is ignored by default if the worker exits before delivery.
        posix_kill($pid, SIGWINCH);
    }

    private function controlLock(InspectionSessionStore $store): InspectionLock
    {
        return $store->lock('control') ?? throw new \RuntimeException('Another inspection command is in progress. Please retry.');
    }

    private function waitForRegistration(InspectionSessionStore $store): void
    {
        $deadline = microtime(true) + 10;

        do {
            $state = $store->read();

            if (($state['pid'] ?? 0) > 0) {
                if ('failed' === $state['phase'] || !$store->isActive()) {
                    throw new \RuntimeException('Inspection preparation failed. See '.$store->logFile());
                }

                return;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        $store->requestStop((string) $store->read()['token']);

        throw new \RuntimeException('The inspection worker did not start. See '.$store->logFile());
    }

    private function autoloadFile(): string
    {
        $autoload = $GLOBALS['_composer_autoload_path'] ?? null;

        if (\is_string($autoload)) {
            return $autoload;
        }

        foreach (ClassLoader::getRegisteredLoaders() as $vendor => $loader) {
            if (is_file($vendor.'/autoload.php')) {
                return $vendor.'/autoload.php';
            }
        }

        throw new \RuntimeException('Cannot locate the consumer Composer autoloader.');
    }
}
