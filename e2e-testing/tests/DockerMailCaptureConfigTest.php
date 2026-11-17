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
use Contao\E2eTesting\Mail\DockerMailCaptureConfig;
use Contao\E2eTesting\Mail\DockerMailCaptureService;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;
use PHPUnit\Framework\TestCase;

final class DockerMailCaptureConfigTest extends TestCase
{
    private string|false $previousImage;

    protected function setUp(): void
    {
        $this->previousImage = getenv('CONTAO_E2E_MAIL_CAPTURE_IMAGE');
        putenv('CONTAO_E2E_MAIL_CAPTURE_IMAGE');
    }

    protected function tearDown(): void
    {
        putenv(false === $this->previousImage ? 'CONTAO_E2E_MAIL_CAPTURE_IMAGE' : 'CONTAO_E2E_MAIL_CAPTURE_IMAGE='.$this->previousImage);
    }

    public function testUsesTheFloatingReleaseTagByDefault(): void
    {
        $this->assertSame('axllent/mailpit:latest', (new DockerMailCaptureConfig())->image);
    }

    public function testDefaultImageCanBeOverriddenWithoutChangingTestCode(): void
    {
        putenv('CONTAO_E2E_MAIL_CAPTURE_IMAGE=registry.example.test/mail-capture:custom');
        $config = new DockerMailCaptureConfig();
        $recipe = InstallationRecipe::create(ComposerConfig::managedEdition('^5.7'));
        $application = ManagedEditionConfig::create($recipe, \dirname(__DIR__, 2))->withMailCapture();

        $this->assertSame('registry.example.test/mail-capture:custom', $config->image);
        $this->assertInstanceOf(DockerMailCaptureConfig::class, $application->mailCapture());
        $this->assertSame($config->image, $application->mailCapture()->image);
    }

    public function testExplicitImageTakesPrecedenceAndIsStableAfterConstruction(): void
    {
        putenv('CONTAO_E2E_MAIL_CAPTURE_IMAGE=registry.example.test/mail-capture:environment');
        $config = new DockerMailCaptureConfig('registry.example.test/mail-capture:explicit');
        putenv('CONTAO_E2E_MAIL_CAPTURE_IMAGE=registry.example.test/mail-capture:changed');

        $this->assertSame('registry.example.test/mail-capture:explicit', $config->image);
    }

    public function testResolvedImagesParticipateInServiceIdentity(): void
    {
        $cache = CacheConfig::forProject(\dirname(__DIR__, 2));
        $default = new DockerMailCaptureService($cache);
        putenv('CONTAO_E2E_MAIL_CAPTURE_IMAGE=registry.example.test/mail-capture:other');
        $other = new DockerMailCaptureService($cache);

        $this->assertNotSame($default->fingerprint(), $other->fingerprint());
    }

    public function testRejectsAnEmptyExplicitImage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DockerMailCaptureConfig(' ');
    }
}
