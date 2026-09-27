<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\ManagedEdition;

use Contao\E2eTesting\Installation\PreparedInstallation;
use Contao\E2eTesting\Process\ContaoConsole;

final readonly class ManagedEditionState
{
    public function __construct(
        public PreparedInstallation $installation,
        public ManagedEditionConfig $config,
        public ContaoConsole $console,
    ) {
    }
}
