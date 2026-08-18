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

use Contao\E2eTestBundle\Cache\ProcessCachedSourceFingerprint;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ProcessCachedSourceFingerprintTest extends TestCase
{
    #[After]
    public function resetCache(): void
    {
        ProcessCachedSourceFingerprint::reset();
    }

    public function testReusesTheFingerprintDuringTheCurrentProcess(): void
    {
        $directory = \dirname(__DIR__, 2).'/.contao-e2e/runtime/unit-tests/source-cache-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory);
        $filesystem->dumpFile($directory.'/Example.php', '<?php return 1;');

        $fingerprint = new ProcessCachedSourceFingerprint();
        $initial = $fingerprint->calculate($directory);

        $filesystem->dumpFile($directory.'/Example.php', '<?php return 2;');

        $this->assertSame($initial, (new ProcessCachedSourceFingerprint())->calculate($directory));
        ProcessCachedSourceFingerprint::reset();
        $this->assertNotSame($initial, $fingerprint->calculate($directory));
    }
}
