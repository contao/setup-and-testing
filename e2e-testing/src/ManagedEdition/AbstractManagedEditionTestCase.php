<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\ManagedEdition;

use Contao\E2eTesting\Docker\DockerServiceProviderInterface;
use PHPUnit\Framework\TestCase;

abstract class AbstractManagedEditionTestCase extends TestCase implements DockerServiceProviderInterface
{
    use ManagedEditionTestTrait;

    public static function dockerServices(): iterable
    {
        return static::createManagedEditionConfig()->dockerServices();
    }
}
