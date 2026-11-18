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

use Contao\E2eTesting\Mail\DockerMailCaptureConfig;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;
use PHPUnit\Framework\TestCase;

final class DockerMailCaptureConfigTest extends TestCase
{
    public function testUsesTheFloatingReleaseTagByDefault(): void
    {
        $this->assertSame('axllent/mailpit:latest', (new DockerMailCaptureConfig())->image);
    }

    public function testSelectsAnExplicitImageForTheApplication(): void
    {
        $config = new DockerMailCaptureConfig('registry.example.test/mail-capture:custom');
        $recipe = InstallationRecipe::create(ComposerConfig::managedEdition('^5.7'));
        $application = ManagedEditionConfig::create($recipe, \dirname(__DIR__, 2))->withMailCapture($config);

        $this->assertSame('registry.example.test/mail-capture:custom', $config->image);
        $this->assertInstanceOf(DockerMailCaptureConfig::class, $application->mailCapture());
        $this->assertSame($config->image, $application->mailCapture()->image);
    }

    public function testRejectsAnEmptyExplicitImage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DockerMailCaptureConfig(' ');
    }
}
