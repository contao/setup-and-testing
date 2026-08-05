<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Tests;

use Contao\E2eTestBundle\Command\E2eApplication;
use PHPUnit\Framework\TestCase;

final class E2eApplicationTest extends TestCase
{
    public function testRegistersTheWorkspaceCommands(): void
    {
        $application = new E2eApplication();

        $this->assertTrue($application->has('doctor'));
        $this->assertTrue($application->has('cache:clear'));
        $this->assertTrue($application->has('database:stop'));
        $this->assertTrue($application->has('failures:clear'));
    }
}
