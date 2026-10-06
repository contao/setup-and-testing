<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Installation;

use Contao\E2eTesting\Cache\FingerprintSet;

final readonly class InstallationWorkspace
{
    public function __construct(
        public string $directory,
        public FingerprintSet $fingerprints,
    ) {
    }
}
