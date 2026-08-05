<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Command;

use Symfony\Component\Console\Application;

final class E2eApplication extends Application
{
    public function __construct()
    {
        parent::__construct('Contao E2E');

        $this->addCommands([
            new DoctorCommand(),
            new CacheClearCommand(),
            new DatabaseStopCommand(),
            new FailuresClearCommand(),
        ]);
        $this->setDefaultCommand('doctor');
    }
}
