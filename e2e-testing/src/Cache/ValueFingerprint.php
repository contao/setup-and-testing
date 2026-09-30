<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Cache;

final readonly class ValueFingerprint
{
    public function calculate(mixed $value): string
    {
        return hash('sha256', serialize($value));
    }
}
