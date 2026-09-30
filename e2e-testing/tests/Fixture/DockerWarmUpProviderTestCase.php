<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Tests\Fixture;

use Contao\E2eTesting\Docker\DockerServiceInterface;
use Contao\E2eTesting\Docker\DockerServiceProviderInterface;
use PHPUnit\Framework\TestCase;

final class DockerWarmUpProviderTestCase extends TestCase implements DockerServiceProviderInterface
{
    public function testServiceWasWarmedBeforeTheTestRuns(): void
    {
        $marker = getenv('CONTAO_E2E_WARM_UP_MARKER');

        $this->assertIsString($marker);
        $this->assertFileExists($marker);
    }

    public static function dockerServices(): iterable
    {
        yield new class() implements DockerServiceInterface {
            public function fingerprint(): string
            {
                return 'subscriber-test';
            }

            public function warmUp(): void
            {
                $marker = getenv('CONTAO_E2E_WARM_UP_MARKER');

                if (!\is_string($marker) || false === file_put_contents($marker, 'warmed')) {
                    throw new \RuntimeException('Could not write the Docker warm-up marker.');
                }
            }
        };
    }
}
