<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Mail;

final readonly class DockerMailCaptureConfig
{
    public function __construct(public string $image = 'axllent/mailpit:latest')
    {
        if ('' === trim($this->image)) {
            throw new \InvalidArgumentException('The mail capture Docker image must not be empty.');
        }
    }
}
