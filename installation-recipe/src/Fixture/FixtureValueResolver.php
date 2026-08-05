<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\Fixture;

use Symfony\Component\Yaml\Tag\TaggedValue;

final readonly class FixtureValueResolver
{
    /**
     * @return list<string>
     */
    public function missingReferences(FixtureDefinition $definition, FixtureRegistry $registry): array
    {
        $missing = [];

        foreach ($definition->data as $value) {
            $this->collectMissingReferences($value, $registry, $missing);
        }

        return array_keys($missing);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(FixtureDefinition $definition, FixtureRegistry $registry): array
    {
        $resolved = [];

        foreach ($definition->data as $column => $value) {
            $resolved[$column] = $this->resolveValue($value, $registry);
        }

        return $resolved;
    }

    private function resolveValue(mixed $value, FixtureRegistry $registry): mixed
    {
        if ($value instanceof TaggedValue) {
            return json_encode($this->resolveStructuredValue($value->getValue(), $registry), JSON_THROW_ON_ERROR);
        }

        if (\is_array($value)) {
            return serialize($this->resolveStructuredValue($value, $registry));
        }

        return $this->resolveScalar($value, $registry);
    }

    private function resolveScalar(mixed $value, FixtureRegistry $registry): mixed
    {
        if (\is_string($value) && str_starts_with($value, '\\@')) {
            return substr($value, 1);
        }

        $reference = FixtureReference::parse($value);

        return !$reference ? $value : $registry->get($reference->name)->value($reference->column);
    }

    private function resolveStructuredValue(mixed $value, FixtureRegistry $registry): mixed
    {
        if ($value instanceof TaggedValue) {
            return $this->resolveValue($value, $registry);
        }

        if (!\is_array($value)) {
            return $this->resolveScalar($value, $registry);
        }

        return array_map(
            fn ($item) => $this->resolveStructuredValue($item, $registry),
            $value,
        );
    }

    /**
     * @param array<string, true> $missing
     */
    private function collectMissingReferences(mixed $value, FixtureRegistry $registry, array &$missing): void
    {
        if ($value instanceof TaggedValue) {
            $value = $value->getValue();
        }

        if (\is_array($value)) {
            foreach ($value as $item) {
                $this->collectMissingReferences($item, $registry, $missing);
            }

            return;
        }

        $reference = FixtureReference::parse($value);

        if ($reference && !$registry->has($reference->name)) {
            $missing[$reference->name] = true;
        }
    }
}
