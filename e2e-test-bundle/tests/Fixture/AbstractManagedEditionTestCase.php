<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Tests\Fixture;

use Contao\E2eTestBundle\ManagedEdition\ManagedEditionTestTrait;
use PHPUnit\Framework\TestCase;

abstract class AbstractManagedEditionTestCase extends TestCase
{
    use ManagedEditionTestTrait;
}
