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

use Contao\InstallationRecipe\Cache\InMemoryCache;
use PHPUnit\Framework\TestCase;

final class InMemoryCacheTest extends TestCase
{
    public function testStoresArbitraryValuesAndDistinguishesCachedNullFromAMiss(): void
    {
        $cache = new InMemoryCache();
        $object = new \stdClass();
        $cache->set('null', null);
        $cache->set('object', $object);
        $cache->set('array', ['first' => 1]);

        $this->assertTrue($cache->has('null'));
        $this->assertNull($cache->get('null'));
        $this->assertFalse($cache->has('missing'));
        $this->assertSame($object, $cache->get('object'));
        $this->assertSame(['first' => 1], $cache->get('array'));
    }

    public function testScopesAreIsolatedAndInvalidationLeavesOtherValuesIntact(): void
    {
        $cache = new InMemoryCache();
        $first = new \stdClass();
        $second = new \stdClass();
        $cache->set('key', 'root');
        $cache->scope($first)->set('key', 'first');
        $cache->scope($second)->set('key', 'second');
        $cache->scope($first)->clear();

        $this->assertFalse($cache->scope($first)->has('key'));
        $this->assertSame('second', $cache->scope($second)->get('key'));
        $this->assertSame('root', $cache->get('key'));
        $this->assertSame($cache->scope($second), $cache->scope($second));
    }

    public function testReleasesScopedValuesWhenTheirOwnerIsDiscarded(): void
    {
        $cache = new InMemoryCache();
        $owner = new \stdClass();
        $value = new \stdClass();
        $cache->scope($owner)->set('key', $value);
        $ownerReference = \WeakReference::create($owner);
        $valueReference = \WeakReference::create($value);
        unset($value, $owner);

        $this->assertNull($ownerReference->get());
        $this->assertNull($valueReference->get());
    }

    public function testClearingParentInvalidatesRetainedScopesAndPreservesTheirBindings(): void
    {
        $cache = new InMemoryCache();
        $owner = new \stdClass();
        $nestedOwner = new \stdClass();
        $scope = $cache->scope($owner);
        $nestedScope = $scope->scope($nestedOwner);
        $scope->set('key', 'scoped');
        $nestedScope->set('key', 'nested');
        $cache->clear();

        $this->assertFalse($scope->has('key'));
        $this->assertFalse($nestedScope->has('key'));
        $this->assertSame($scope, $cache->scope($owner));
        $this->assertSame($nestedScope, $scope->scope($nestedOwner));
        $scope->set('key', 'fresh');

        $this->assertSame('fresh', $cache->scope($owner)->get('key'));
    }

    public function testClearingValuesInvalidatesScopesWhoseOwnersWereCached(): void
    {
        $cache = new InMemoryCache();
        $owner = new \stdClass();
        $scope = $cache->scope($owner);
        $cache->set('owner', $owner);
        $scope->set('key', 'scoped');
        unset($owner);
        $cache->clear();

        $this->assertFalse($scope->has('key'));
    }

    public function testClearingInvalidatesNestedScopesWhenAnotherScopeHoldsTheirOwner(): void
    {
        $cache = new InMemoryCache();
        $firstOwner = new \stdClass();
        $secondOwner = new \stdClass();
        $nestedOwner = new \stdClass();
        $first = $cache->scope($firstOwner);
        $second = $cache->scope($secondOwner);
        $nested = $second->scope($nestedOwner);
        $first->set('owner', $nestedOwner);
        $nested->set('key', 'nested');
        unset($nestedOwner);
        $cache->clear();

        $this->assertFalse($nested->has('key'));
    }

    public function testClearingTheCacheRemovesRootAndScopedValues(): void
    {
        $cache = new InMemoryCache();
        $owner = new \stdClass();
        $cache->set('key', 'root');
        $cache->scope($owner)->set('key', 'scoped');
        $cache->clear();

        $this->assertFalse($cache->has('key'));
        $this->assertFalse($cache->scope($owner)->has('key'));
    }
}
