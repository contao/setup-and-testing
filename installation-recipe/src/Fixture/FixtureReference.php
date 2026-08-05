<?php

declare(strict_types=1);

namespace Contao\InstallationRecipe\Fixture;

use Contao\InstallationRecipe\Exception\InvalidRecipeException;

final readonly class FixtureReference
{
    private function __construct(
        public string $name,
        public string|null $column,
    ) {
    }

    public static function parse(mixed $value): self|null
    {
        if (!\is_string($value) || !str_starts_with($value, '@')) {
            return null;
        }

        if (!preg_match('/^@([A-Za-z_][A-Za-z0-9_.\/-]*)(?:->([A-Za-z_][A-Za-z0-9_]*))?$/D', $value, $matches)) {
            throw new InvalidRecipeException(\sprintf('The fixture reference "%s" is invalid.', $value));
        }

        return new self($matches[1], $matches[2] ?? null);
    }
}
