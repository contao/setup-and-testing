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

use Contao\InstallationRecipe\Cache\InMemoryCache;
use Contao\InstallationRecipe\Exception\InvalidRecipeException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

final class FixtureParser
{
    /**
     * @var array<string, true>
     */
    private array $names = [];

    public function __construct(private readonly InMemoryCache $cache)
    {
    }

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
        $tables = $this->parseYaml($file);

        if (!\is_array($tables)) {
            throw new InvalidRecipeException(\sprintf('The fixture file "%s" must contain a table mapping.', $file));
        }

        $definitions = [];

        foreach ($tables as $table => $rows) {
            array_push($definitions, ...$this->parseTable($file, $table, $rows));
        }

        return $definitions;
    }

    private function parseYaml(string $file): mixed
    {
        if (!is_file($file) || !is_readable($file)) {
            return Yaml::parseFile($file, Yaml::PARSE_CUSTOM_TAGS);
        }

        $fingerprint = hash_file('sha256', $file);
        $key = 'fixture.yaml:'.$fingerprint;

        if (false !== $fingerprint && $this->cache->has($key)) {
            return $this->cache->get($key);
        }

        $tables = Yaml::parseFile($file, Yaml::PARSE_CUSTOM_TAGS);

        // Cache only when the file stayed unchanged while YAML was parsed.
        if (false !== $fingerprint && hash_file('sha256', $file) === $fingerprint) {
            $this->cache->set($key, $tables);
        }

        return $tables;
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

        foreach ($row as $value) {
            $this->validateTags($value, $file);
        }

        return $row;
    }

    private function validateTags(mixed $value, string $file): void
    {
        if ($value instanceof TaggedValue) {
            if ('json' !== $value->getTag()) {
                throw new InvalidRecipeException(\sprintf('The fixture value tag "!%s" in "%s" is invalid.', $value->getTag(), $file));
            }

            $value = $value->getValue();
        }

        if (\is_array($value)) {
            foreach ($value as $item) {
                $this->validateTags($item, $file);
            }
        }
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
