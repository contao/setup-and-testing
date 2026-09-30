<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\PhpUnit;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\TestSuite\Started;
use PHPUnit\Event\TestSuite\StartedSubscriber;

final readonly class DockerWarmUpSubscriber implements StartedSubscriber
{
    /**
     * @param list<string> $testSuites
     */
    public function __construct(private array $testSuites)
    {
    }

    public function notify(Started $event): void
    {
        $classes = [];

        foreach ($event->testSuite()->tests() as $test) {
            if ($test instanceof TestMethod) {
                $classes[] = $test->className();
            }
        }

        DockerWarmUpPlan::forTestClasses($this->testSuites, $event->testSuite()->name(), $classes)->warmUp();
    }
}
