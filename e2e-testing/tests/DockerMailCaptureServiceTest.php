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

use Contao\E2eTesting\Cache\CacheConfig;
use Contao\E2eTesting\Exception\MailCaptureException;
use Contao\E2eTesting\Mail\DockerMailCaptureConfig;
use Contao\E2eTesting\Mail\DockerMailCaptureService;
use Contao\E2eTesting\Process\ProcessRunnerInterface;
use PHPUnit\Framework\TestCase;

final class DockerMailCaptureServiceTest extends TestCase
{
    public function testFingerprintsIncludeProjectCacheAndImage(): void
    {
        $cache = CacheConfig::forProject(\dirname(__DIR__, 2));
        $default = new DockerMailCaptureService($cache);

        $this->assertSame($default->fingerprint(), (new DockerMailCaptureService($cache))->fingerprint());
        $this->assertNotSame($default->fingerprint(), (new DockerMailCaptureService($cache, new DockerMailCaptureConfig('mail-capture:other')))->fingerprint());
        $this->assertNotSame($default->fingerprint(), (new DockerMailCaptureService($cache->withRootDirectory('/different-cache')))->fingerprint());
        $this->assertNotSame($default->fingerprint(), (new DockerMailCaptureService(CacheConfig::forProject('/different-project')))->fingerprint());
    }

    public function testFailedStartupRemovesOnlyItsOwnContainer(): void
    {
        $calls = [];
        $runner = $this->createMock(ProcessRunnerInterface::class);
        $runner
            ->expects($this->exactly(3))
            ->method('run')
            ->willReturnCallback(
                static function (array $command) use (&$calls): string {
                    $calls[] = $command;

                    return 'inspect' === $command[1] ? '{"8025/tcp":null}' : '';
                },
            )
        ;
        $service = new DockerMailCaptureService(CacheConfig::forProject(\dirname(__DIR__, 2)), new DockerMailCaptureConfig(), $runner);

        try {
            $service->warmUp();
            $this->fail('Expected startup to fail with an unpublished port.');
        } catch (MailCaptureException $exception) {
            $this->assertStringContainsString('Docker did not publish', $exception->getMessage());
        }

        $name = $calls[0][5];
        $this->assertStringStartsWith('contao-e2e-mail-capture-', $name);
        $this->assertContains('127.0.0.1::1025', $calls[0]);
        $this->assertContains('127.0.0.1::8025', $calls[0]);
        $this->assertSame(['docker', 'rm', '--force', $name], $calls[2]);
    }
}
