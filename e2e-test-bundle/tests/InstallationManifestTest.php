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

use Contao\E2eTestBundle\Installation\InstallationManifest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class InstallationManifestTest extends TestCase
{
    public function testRoundTripsTheManifest(): void
    {
        $directory = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests';
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory);

        $path = $filesystem->tempnam($directory, 'manifest-');
        $manifest = new InstallationManifest('dependency', 'application', ['files/example.txt']);
        $manifest->write($path);

        $restored = InstallationManifest::read($path);

        $this->assertNotNull($restored);
        $this->assertSame($manifest->dependency, $restored->dependency);
        $this->assertSame($manifest->application, $restored->application);
        $this->assertSame($manifest->mappedTargets, $restored->mappedTargets);
    }
}
