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

final readonly class InspectionDefinitionLoader
{
    public function load(string $file): \Closure|ManagedEditionConfig
    {
        $path = realpath($file);

        if (false === $path || !is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('The inspection file must be an existing readable PHP file.');
        }

        $definition = (static fn (string $path): mixed => require $path)($path);

        if ($definition instanceof ManagedEditionConfig) {
            return $definition;
        }

        if (\is_callable($definition)) {
            return \Closure::fromCallable($definition);
        }

        throw new \InvalidArgumentException('The inspection file must return a ManagedEditionConfig or a factory callable.');
    }
}
