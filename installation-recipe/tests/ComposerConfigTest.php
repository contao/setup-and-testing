<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\Tests;

use Contao\InstallationRecipe\Composer\ComposerConfig;
use PHPUnit\Framework\TestCase;

final class ComposerConfigTest extends TestCase
{
    public function testBuildsAnImmutableManagedEditionConfiguration(): void
    {
        $base = ComposerConfig::managedEdition('5.7.*');
        $configured = $base
            ->require('contao/news-bundle', '^5.7')
            ->withPathPackage('acme/example', __DIR__, '1.0.x-dev')
        ;

        $this->assertSame(['contao/manager-bundle' => '5.7.*'], $base->toArray(__DIR__)['require']);
        $this->assertSame('assets', $base->toArray(__DIR__)['extra']['contao-component-dir']);
        $this->assertSame('1.0.x-dev', $configured->toArray(__DIR__)['require']['acme/example']);
        $this->assertSame('.', $configured->toArray(__DIR__)['repositories'][0]['url']);
        $this->assertTrue($configured->toArray(__DIR__)['repositories'][0]['options']['symlink']);
    }
}
