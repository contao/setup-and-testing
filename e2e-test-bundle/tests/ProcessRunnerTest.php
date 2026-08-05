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

use Contao\E2eTestBundle\Process\ProcessRunner;
use PHPUnit\Framework\TestCase;

class ProcessRunnerTest extends TestCase
{
    public function testDisablesXdebug(): void
    {
        $output = (new ProcessRunner())->run(
            [PHP_BINARY, '-r', 'echo getenv("XDEBUG_MODE");'],
            sys_get_temp_dir(),
            ['XDEBUG_MODE' => 'debug'],
        );

        $this->assertSame('off', $output);
    }
}
