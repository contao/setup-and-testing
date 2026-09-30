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

use Contao\E2eTesting\Docker\DockerServiceInterface;
use Contao\E2eTesting\Docker\DockerServiceProviderInterface;

final readonly class DockerWarmUpPlan
{
    /**
     * @param list<DockerServiceInterface> $services
     */
    private function __construct(public array $services)
    {
    }

    public function warmUp(): void
    {
        foreach ($this->services as $service) {
            $service->warmUp();
        }
    }

    /**
     * @param list<string>       $testSuites
     * @param list<class-string> $testClasses
     */
    public static function forTestClasses(array $testSuites, string $testSuite, array $testClasses): self
    {
        if (!\in_array($testSuite, $testSuites, true)) {
            return new self([]);
        }

        $services = [];

        foreach (array_unique($testClasses) as $class) {
            if (!is_subclass_of($class, DockerServiceProviderInterface::class)) {
                continue;
            }

            foreach ($class::dockerServices() as $service) {
                $services[$service->fingerprint()] = $service;
            }
        }

        return new self(array_values($services));
    }
}
