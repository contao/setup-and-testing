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

use Contao\E2eTesting\Application\ApplicationInterface;

final class InspectionSession
{
    private function __construct(
        private readonly InspectionSessionStore $store,
        private readonly string $token,
        private readonly InspectionLock $lock,
    ) {
    }

    public function __destruct()
    {
        $this->lock->release();
    }

    public static function open(InspectionSessionStore $store, string $token): self
    {
        $lock = self::acquireLock($store, $token);
        $state = $store->read();

        $store->write(array_replace($state, [
            'pid' => getmypid(),
            'phase' => 'starting',
            'interruptible' => \function_exists('pcntl_signal') && \defined('SIGWINCH') ? 1 : 0,
        ]));

        return new self($store, $token, $lock);
    }

    public function ready(ApplicationInterface $application): void
    {
        $this->store->write(array_replace(
            $this->store->read(),
            ['phase' => 'running'],
            InspectionDetails::forApplication($application)->values,
        ));
    }

    public function stopRequested(): bool
    {
        return $this->store->stopRequested($this->token);
    }

    public function finish(int $exitCode): void
    {
        $this->store->write(array_replace($this->store->read(), ['phase' => 0 === $exitCode ? 'stopped' : 'failed']));
    }

    private static function acquireLock(InspectionSessionStore $store, string $token): InspectionLock
    {
        $deadline = microtime(true) + 2;

        do {
            $lock = $store->lock('session');

            if (($store->read()['token'] ?? null) !== $token) {
                throw new \RuntimeException('The inspection session has already been replaced.');
            }

            if ($lock) {
                return $lock;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException('Cannot acquire the inspection session lock.');
    }
}
