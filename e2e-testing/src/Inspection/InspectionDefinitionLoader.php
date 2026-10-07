<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Inspection;

use Contao\E2eTesting\Application\ApplicationConfigInterface;

final readonly class InspectionDefinitionLoader
{
    public function load(string $file): ApplicationConfigInterface|\Closure
    {
        $path = realpath($file);

        if (false === $path || !is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('The inspection file must be an existing readable PHP file.');
        }

        $definition = (static fn (string $path): mixed => require $path)($path);

        if ($definition instanceof ApplicationConfigInterface) {
            return $definition;
        }

        if (\is_callable($definition)) {
            return \Closure::fromCallable($definition);
        }

        throw new \InvalidArgumentException('The inspection file must return an ApplicationConfigInterface or a factory callable.');
    }
}
