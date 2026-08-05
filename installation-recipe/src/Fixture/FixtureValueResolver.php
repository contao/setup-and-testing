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
        if (\is_array($value)) {
            return serialize($this->resolveArray($value, $registry));
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

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function resolveArray(array $values, FixtureRegistry $registry): array
    {
        return array_map(
            fn ($value) => \is_array($value) ? $this->resolveArray($value, $registry) : $this->resolveScalar($value, $registry),
            $values,
        );
    }

    /**
     * @param array<string, true> $missing
     */
    private function collectMissingReferences(mixed $value, FixtureRegistry $registry, array &$missing): void
    {
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
