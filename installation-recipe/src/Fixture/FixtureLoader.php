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
use Doctrine\DBAL\Connection;

final readonly class FixtureLoader
{
    public function __construct(
        private FixtureParser $parser = new FixtureParser(),
        private FixtureValueResolver $valueResolver = new FixtureValueResolver(),
    ) {
    }

    public function load(Connection $connection, FixtureSet $fixtures): FixtureResult
    {
        $definitions = $this->parser->parse($fixtures);

        return $connection->transactional(fn () => $this->insertDefinitions($connection, $definitions));
    }

    /**
     * @param list<FixtureDefinition> $definitions
     */
    private function insertDefinitions(Connection $connection, array $definitions): FixtureResult
    {
        $registry = new FixtureRegistry();
        $context = new FixtureLoadContext($connection, $registry, new TableIdentityResolver($connection));

        while ([] !== $definitions) {
            [$definitions, $inserted] = $this->insertResolvable($context, $definitions);

            if (!$inserted) {
                throw new InvalidRecipeException($this->unresolvedMessage($definitions, $context->registry));
            }
        }

        return $registry->result();
    }

    /**
     * @param list<FixtureDefinition> $definitions
     *
     * @return array{list<FixtureDefinition>, bool}
     */
    private function insertResolvable(FixtureLoadContext $context, array $definitions): array
    {
        $pending = [];
        $inserted = false;

        foreach ($definitions as $definition) {
            if ([] !== $this->valueResolver->missingReferences($definition, $context->registry)) {
                $pending[] = $definition;
                continue;
            }

            $this->insert($context, $definition);
            $inserted = true;
        }

        return [$pending, $inserted];
    }

    private function insert(FixtureLoadContext $context, FixtureDefinition $definition): void
    {
        $data = $this->valueResolver->resolve($definition, $context->registry);
        $context->identityResolver->prepare($definition);
        $context->connection->insert(
            $context->connection->getDatabasePlatform()->quoteSingleIdentifier($definition->source->table),
            $this->quoteColumns($context->connection, $data),
        );

        if (null !== $definition->name) {
            $context->registry->register($definition->name, $context->identityResolver->resolve($definition, $data));
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function quoteColumns(Connection $connection, array $data): array
    {
        $quoted = [];

        foreach ($data as $column => $value) {
            $quoted[$connection->getDatabasePlatform()->quoteSingleIdentifier($column)] = $value;
        }

        return $quoted;
    }

    /**
     * @param list<FixtureDefinition> $definitions
     */
    private function unresolvedMessage(array $definitions, FixtureRegistry $registry): string
    {
        $unresolved = [];

        foreach ($definitions as $definition) {
            foreach ($this->valueResolver->missingReferences($definition, $registry) as $reference) {
                $unresolved[$reference] = true;
            }
        }

        return 'Fixture dependencies cannot be resolved: '.implode(', ', array_keys($unresolved)).'.';
    }
}
