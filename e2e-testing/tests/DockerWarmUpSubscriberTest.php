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

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class DockerWarmUpSubscriberTest extends TestCase
{
    public function testWarmsServicesAdvertisedByTestsInTheSelectedSuite(): void
    {
        $root = \dirname(__DIR__, 2);
        $directory = $root.'/.contao-e2e/runtime/unit-tests/warm-up-subscriber-'.bin2hex(random_bytes(6));
        $marker = $directory.'/warmed';
        $configuration = $directory.'/phpunit.xml';
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory);
        $filesystem->dumpFile($configuration, $this->configuration($root, $directory));

        $process = new Process(
            [PHP_BINARY, $root.'/vendor/bin/phpunit', '--configuration='.$configuration],
            $root,
            ['CONTAO_E2E_WARM_UP_MARKER' => $marker],
        );

        try {
            $process->mustRun();

            $this->assertFileExists($marker);
        } finally {
            $filesystem->remove($directory);
        }
    }

    private function configuration(string $root, string $cache): string
    {
        $bootstrap = htmlspecialchars($root.'/vendor/autoload.php', ENT_XML1);
        $fixture = htmlspecialchars(__DIR__.'/Fixture/DockerWarmUpProviderTestCase.php', ENT_XML1);
        $cache = htmlspecialchars($cache.'/cache', ENT_XML1);

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit bootstrap="$bootstrap" cacheDirectory="$cache">
              <testsuites>
                <testsuite name="warm-up">
                  <file>$fixture</file>
                </testsuite>
              </testsuites>
              <extensions>
                <bootstrap class="Contao\\E2eTesting\\PhpUnit\\DockerWarmUpExtension">
                  <parameter name="testsuites" value="warm-up"/>
                </bootstrap>
              </extensions>
            </phpunit>
            XML;
    }
}
