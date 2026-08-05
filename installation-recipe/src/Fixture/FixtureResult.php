<?php

declare(strict_types=1);

namespace Contao\InstallationRecipe\Fixture;

use Contao\InstallationRecipe\Exception\InvalidRecipeException;

final readonly class FixtureResult
{
    /**
     * @param array<string, FixtureIdentity> $identities
     */
    public function __construct(private array $identities)
    {
    }

    public function value(string $fixture, string|null $column = null): mixed
    {
        $identity = $this->identities[$fixture] ?? throw new InvalidRecipeException(\sprintf('The fixture "%s" does not exist.', $fixture));

        return $identity->value($column);
    }

    public function interpolate(string $value): string
    {
        return preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_.\/-]*)(?:->([A-Za-z_][A-Za-z0-9_]*))?\}/',
            fn (array $match) => (string) $this->value($match[1], $match[2] ?? null),
            $value,
        );
    }
}
