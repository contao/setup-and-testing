<?php

declare(strict_types=1);

namespace Contao\InstallationRecipe\Fixture;

final readonly class FixtureDefinition
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public FixtureSource $source,
        public string|null $name,
        public array $data,
    ) {
    }
}
