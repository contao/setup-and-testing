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

final class InspectionLock
{
    /**
     * @var resource|null
     */
    private $stream;

    /**
     * @param resource $stream
     */
    private function __construct($stream)
    {
        $this->stream = $stream;
    }

    public function __destruct()
    {
        $this->release();
    }

    public function release(): void
    {
        if (null === $this->stream) {
            return;
        }

        flock($this->stream, LOCK_UN);
        fclose($this->stream);
        $this->stream = null;
    }

    public static function acquire(string $path): self|null
    {
        $stream = fopen($path, 'c+');

        if (false === $stream) {
            throw new \RuntimeException('Cannot open inspection lock: '.$path);
        }

        if (!flock($stream, LOCK_EX | LOCK_NB)) {
            fclose($stream);

            return null;
        }

        return new self($stream);
    }
}
