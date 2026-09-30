<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Tests;

use Contao\E2eTesting\Database\DockerDatabaseService;
use Contao\E2eTesting\Tests\Fixture\AbstractManagedEditionTestCase;
use PHPUnit\Framework\TestCase;

final class AbstractManagedEditionTestCaseTest extends TestCase
{
    public function testProvidesTheConfiguredDockerDatabaseService(): void
    {
        $services = [...AbstractManagedEditionTestCase::dockerServices()];

        $this->assertCount(1, $services);
        $this->assertInstanceOf(DockerDatabaseService::class, $services[0]);
        $this->assertSame('mysql:8.4', $services[0]->config->image);
    }
}
