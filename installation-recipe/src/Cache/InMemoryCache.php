<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\Cache;

final class InMemoryCache
{
    /**
     * @var array<string, mixed>
     */
    private array $values = [];

    /**
     * @var \WeakMap<object, self>
     */
    private \WeakMap $scopes;

    public function __construct()
    {
        $this->scopes = new \WeakMap();
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->values);
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function scope(object $owner): self
    {
        return $this->scopes[$owner] ??= new self();
    }

    public function clear(): void
    {
        // Collect all scopes before clearing values can release their weak owners.
        $scopes = $this->allScopes();

        foreach ($scopes as $scope) {
            $scope->values = [];
        }
    }

    /**
     * @return list<self>
     */
    private function allScopes(): array
    {
        $scopes = [$this];

        foreach ($this->scopes as $scope) {
            array_push($scopes, ...$scope->allScopes());
        }

        return $scopes;
    }
}
