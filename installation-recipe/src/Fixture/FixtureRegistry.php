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

use Contao\InstallationRecipe\Exception\InvalidRecipeException;

final class FixtureRegistry
{
    /**
     * @var array<string, FixtureIdentity>
     */
    private array $identities = [];

    public function has(string $name): bool
    {
        return isset($this->identities[$name]);
    }

    public function get(string $name): FixtureIdentity
    {
        return $this->identities[$name] ?? throw new InvalidRecipeException(\sprintf('The fixture "%s" does not exist.', $name));
    }

    public function register(string $name, FixtureIdentity $identity): void
    {
        $this->identities[$name] = $identity;
    }

    public function result(): FixtureResult
    {
        return new FixtureResult($this->identities);
    }
}
