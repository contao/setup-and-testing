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

use Contao\E2eTestBundle\Cache\CacheConfig;
use Contao\E2eTestBundle\Cache\WorkspaceInitializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class WorkspaceInitializerTest extends TestCase
{
    public function testCreatesTheProjectLocalWorkspace(): void
    {
        $project = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests/workspace-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($project);
        $config = CacheConfig::forProject($project);

        (new WorkspaceInitializer())->initialize($config);

        $this->assertFileExists($project.'/.contao-e2e/.managed-by-contao-e2e');
        $this->assertSame("*\n!.gitignore\n", file_get_contents($project.'/.contao-e2e/.gitignore'));
        $this->assertDirectoryExists($project.'/.contao-e2e/cache/installations');
        $this->assertDirectoryExists($project.'/.contao-e2e/database');
    }
}
