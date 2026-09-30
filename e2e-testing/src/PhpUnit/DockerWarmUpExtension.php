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

use Contao\E2eTesting\Exception\E2eTestException;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

final readonly class DockerWarmUpExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber($this->subscriber($parameters));
    }

    private function subscriber(ParameterCollection $parameters): DockerWarmUpSubscriber
    {
        if (!$parameters->has('testsuites')) {
            throw new E2eTestException('The Docker warm-up extension requires a comma-separated "testsuites" parameter.');
        }

        $testSuites = $this->values($parameters->get('testsuites'));

        if ([] === $testSuites) {
            throw new E2eTestException('The Docker warm-up extension requires at least one test suite.');
        }

        return new DockerWarmUpSubscriber($testSuites);
    }

    /**
     * @return list<string>
     */
    private function values(string $value): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $value))));
    }
}
