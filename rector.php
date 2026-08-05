<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

use Contao\Rector\Set\SetList;
use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\Php84\Rector\Class_\DeprecatedAnnotationToDeprecatedAttributeRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withPhpSets(php82: true)
    ->withSets([SetList::CONTAO])
    ->withPaths([
        __DIR__.'/e2e-test-bundle/bin',
        __DIR__.'/e2e-test-bundle/src',
        __DIR__.'/e2e-test-bundle/tests',
        __DIR__.'/installation-recipe/src',
        __DIR__.'/installation-recipe/tests',
    ])
    ->withSkip([
        AddOverrideAttributeToOverriddenMethodsRector::class,
        ClassPropertyAssignToConstructorPromotionRector::class => [
            'e2e-test-bundle/src/InstallationLease.php',
        ],
        DeprecatedAnnotationToDeprecatedAttributeRector::class,
        ReadOnlyClassRector::class,
    ])
    ->withRootFiles()
    ->withParallel()
    ->withCache(sys_get_temp_dir().'/rector/contao-setup-and-testing')
;
