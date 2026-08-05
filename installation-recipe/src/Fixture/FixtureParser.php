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
use Symfony\Component\Yaml\Yaml;

final class FixtureParser
{
    /**
     * @var array<string, true>
     */
    private array $names = [];

    /**
     * @return list<FixtureDefinition>
     */
    public function parse(FixtureSet $fixtures): array
    {
        $this->names = [];
        $definitions = [];

        foreach ($fixtures->files as $file) {
            array_push($definitions, ...$this->parseFile($file));
        }

        return $definitions;
    }

    /**
     * @return list<FixtureDefinition>
     */
    private function parseFile(string $file): array
    {
        $tables = Yaml::parseFile($file);

        if (!\is_array($tables)) {
            throw new InvalidRecipeException(\sprintf('The fixture file "%s" must contain a table mapping.', $file));
        }

        $definitions = [];

        foreach ($tables as $table => $rows) {
            array_push($definitions, ...$this->parseTable($file, $table, $rows));
        }

        return $definitions;
    }

    /**
     * @return list<FixtureDefinition>
     */
    private function parseTable(string $file, mixed $table, mixed $rows): array
    {
        $this->validateIdentifier($table, 'table', $file);

        if ('sql' === strtolower((string) $table) || !\is_array($rows)) {
            throw new InvalidRecipeException(\sprintf('The fixture table "%s" in "%s" is invalid.', $table, $file));
        }

        $source = new FixtureSource($file, $table);

        if (array_is_list($rows)) {
            return array_map(fn ($row) => new FixtureDefinition($source, null, $this->validateRow($row, $file)), $rows);
        }

        $definitions = [];

        foreach ($rows as $name => $row) {
            $this->validateFixtureName($name, $file);
            $definitions[] = new FixtureDefinition($source, $name, $this->validateRow($row, $file));
        }

        return $definitions;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRow(mixed $row, string $file): array
    {
        if (!\is_array($row)) {
            throw new InvalidRecipeException(\sprintf('Every row in "%s" must be a mapping.', $file));
        }

        foreach (array_keys($row) as $column) {
            $this->validateIdentifier($column, 'column', $file);
        }

        return $row;
    }

    private function validateFixtureName(mixed $name, string $file): void
    {
        if (!\is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_.\/-]*$/D', $name)) {
            throw new InvalidRecipeException(\sprintf('The fixture name "%s" in "%s" is invalid.', (string) $name, $file));
        }

        if (isset($this->names[$name])) {
            throw new InvalidRecipeException(\sprintf('The fixture name "%s" is defined more than once.', $name));
        }

        $this->names[$name] = true;
    }

    private function validateIdentifier(mixed $identifier, string $type, string $file): void
    {
        if (!\is_string($identifier) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier)) {
            throw new InvalidRecipeException(\sprintf('The fixture %s "%s" in "%s" is invalid.', $type, (string) $identifier, $file));
        }
    }
}
