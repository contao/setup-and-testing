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

use Contao\E2eTestBundle\Cache\FingerprintSet;
use Contao\E2eTestBundle\Database\DatabaseManager;

final readonly class PreparedInstallation
{
    public function __construct(
        public InstallationLease $lease,
        public DatabaseManager $database,
        public FingerprintSet $fingerprints,
    ) {
    }

    public function directory(): string
    {
        return $this->lease->directory.'/project';
    }
}
