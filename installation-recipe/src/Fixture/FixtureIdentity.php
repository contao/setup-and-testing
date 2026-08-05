<?php

declare(strict_types=1);

namespace Contao\InstallationRecipe\Fixture;

use Contao\InstallationRecipe\Exception\InvalidRecipeException;

final readonly class FixtureIdentity
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private string|null $identityColumn,
        private array $data,
    ) {
    }

    public function value(string|null $column = null): mixed
    {
        $column ??= $this->identityColumn;

        if (null === $column) {
            throw new InvalidRecipeException('The referenced fixture has no single primary key. Use @fixture->column instead.');
        }

        if (!\array_key_exists($column, $this->data)) {
            throw new InvalidRecipeException(\sprintf('The referenced fixture has no column "%s".', $column));
        }

        return $this->data[$column];
    }
}
