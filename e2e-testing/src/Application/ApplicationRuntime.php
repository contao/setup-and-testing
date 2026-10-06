<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Application;

use Contao\InstallationRecipe\Cache\InMemoryCache;

final class ApplicationRuntime
{
    private static self|null $shared = null;

    public function __construct(public readonly InMemoryCache $cache)
    {
    }

    public static function create(): self
    {
        return new self(new InMemoryCache());
    }

    public static function shared(): self
    {
        return self::$shared ??= self::create();
    }

    public function createApplication(ApplicationConfigInterface $config): ApplicationInterface
    {
        return $config->createApplication($this);
    }
}
