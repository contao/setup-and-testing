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

final class DockerDatabaseExclusiveLock
{
    /**
     * @param resource|null $lock
     */
    private function __construct(private mixed $lock)
    {
    }

    public function __destruct()
    {
        $this->release();
    }

    public static function acquire(string $lockPath): self|null
    {
        $lock = fopen($lockPath, 'c+');

        if (false === $lock) {
            throw new E2eTestException(\sprintf('Could not open the Docker database lease "%s".', $lockPath));
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return null;
        }

        return new self($lock);
    }

    public function release(): void
    {
        if (null === $this->lock) {
            return;
        }

        flock($this->lock, LOCK_UN);
        fclose($this->lock);
        $this->lock = null;
    }
}
