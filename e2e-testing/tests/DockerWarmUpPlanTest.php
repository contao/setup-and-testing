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

use Contao\E2eTesting\Docker\DockerServiceInterface;
use Contao\E2eTesting\Docker\DockerServiceProviderInterface;
use Contao\E2eTesting\PhpUnit\DockerWarmUpPlan;
use PHPUnit\Framework\TestCase;

final class DockerWarmUpPlanTest extends TestCase
{
    public static int $warmUps = 0;

    public function testCollectsDistinctServicesFromProvidersInConfiguredSuites(): void
    {
        self::$warmUps = 0;
        $testClass = $this->testClass();

        $skippedPlan = DockerWarmUpPlan::forTestClasses(['e2e'], 'unit', [$testClass]);
        $skippedPlan->warmUp();
        $this->assertSame(0, self::$warmUps);

        $plan = DockerWarmUpPlan::forTestClasses(['e2e'], 'e2e', [$testClass, $testClass, self::class]);

        $this->assertCount(1, $plan->services);
        $this->assertSame('example', $plan->services[0]->fingerprint());

        $plan->warmUp();
        $this->assertSame(1, self::$warmUps);
    }

    /**
     * @return class-string
     */
    private function testClass(): string
    {
        $test = new class() implements DockerServiceProviderInterface {
            public static function dockerServices(): iterable
            {
                yield new class() implements DockerServiceInterface {
                    public function fingerprint(): string
                    {
                        return 'example';
                    }

                    public function warmUp(): void
                    {
                        ++DockerWarmUpPlanTest::$warmUps;
                    }
                };
            }
        };

        return $test::class;
    }
}
