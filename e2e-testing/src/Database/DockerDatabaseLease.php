<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Database;

use Contao\E2eTesting\Exception\E2eTestException;

final class DockerDatabaseLease
{
    /**
     * @param resource|null $lock
     */
    private function __construct(
        private mixed $lock,
        private readonly \Closure $stop,
    ) {
    }

    public function __destruct()
    {
        try {
            $this->release();
        } catch (\Throwable) {
        }
    }

    public static function acquire(string $lockPath, \Closure $stop): self
    {
        $lock = fopen($lockPath, 'c+');

        if (false === $lock) {
            throw new E2eTestException(\sprintf('Could not open the Docker database lease "%s".', $lockPath));
        }

        if (!flock($lock, LOCK_SH)) {
            fclose($lock);

            throw new E2eTestException(\sprintf('Could not acquire the Docker database lease "%s".', $lockPath));
        }

        return new self($lock, $stop);
    }

    public function release(): void
    {
        if (null === $this->lock) {
            return;
        }

        $lock = $this->lock;
        $this->lock = null;
        flock($lock, LOCK_UN);

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return;
        }

        try {
            ($this->stop)();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
