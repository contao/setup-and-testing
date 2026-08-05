<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Installation;

final class InstallationLease
{
    /**
     * @param resource|null $lock
     */
    public function __construct(
        public readonly string $directory,
        public readonly int $slot,
        private mixed $lock,
    ) {
    }

    public function __destruct()
    {
        $this->release();
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
