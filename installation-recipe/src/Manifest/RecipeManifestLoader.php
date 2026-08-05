<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\Manifest;

use Contao\InstallationRecipe\Composer\ComposerDependencies;
use Contao\InstallationRecipe\Configuration\ConfigFragment;
use Contao\InstallationRecipe\Exception\InvalidRecipeException;
use Contao\InstallationRecipe\File\FileMapping;
use Contao\InstallationRecipe\Fixture\FixtureSet;
use Contao\InstallationRecipe\Recipe\PortableInstallationRecipe;
use Contao\InstallationRecipe\Recipe\RecipeAssets;
use Contao\InstallationRecipe\Recipe\RecipeContent;
use Contao\InstallationRecipe\Recipe\RecipeDescriptor;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class RecipeManifestLoader
{
    public function load(string $directory): PortableInstallationRecipe
    {
        $manifest = $this->parse(Path::join($directory, 'recipe.yaml'));
        $this->assertKeys($manifest, ['format', 'name', 'composer', 'config', 'fixtures', 'files'], 'manifest');
        $descriptor = new RecipeDescriptor($this->name($manifest), $this->format($manifest));
        $dependencies = $this->dependencies($directory, $manifest['composer'] ?? null);
        $content = new RecipeContent(
            new FixtureSet($this->paths($directory, $manifest['fixtures'] ?? [], 'fixtures')),
            new RecipeAssets(
                $this->configFragments($directory, $manifest['config'] ?? []),
                $this->fileMappings($directory, $manifest['files'] ?? []),
            ),
        );

        return new PortableInstallationRecipe($descriptor, $dependencies, $content);
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $path): array
    {
        if (!is_file($path)) {
            throw new InvalidRecipeException('The recipe archive does not contain a recipe.yaml file.');
        }

        try {
            $manifest = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new InvalidRecipeException('The recipe manifest is invalid: '.$exception->getMessage(), 0, $exception);
        }

        if (!\is_array($manifest)) {
            throw new InvalidRecipeException('The recipe manifest must contain a YAML mapping.');
        }

        /** @var array<string, mixed> $manifest */
        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function name(array $manifest): string
    {
        $name = $manifest['name'] ?? null;

        if (!\is_string($name) || 1 !== preg_match('#^[a-z0-9](?:[a-z0-9._-]*/?)+$#', $name)) {
            throw new InvalidRecipeException('The recipe name must be a lowercase package-style name.');
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function format(array $manifest): int
    {
        if (1 !== ($manifest['format'] ?? null)) {
            throw new InvalidRecipeException('Only recipe manifest format 1 is supported.');
        }

        return 1;
    }

    private function dependencies(string $directory, mixed $file): ComposerDependencies
    {
        if (null === $file) {
            return new ComposerDependencies();
        }

        return ComposerDependencies::fromFile($this->filePath($directory, $file, 'composer'));
    }

    /**
     * @return list<ConfigFragment>
     */
    private function configFragments(string $directory, mixed $files): array
    {
        return array_map(
            static fn (string $path) => new ConfigFragment($path),
            $this->paths($directory, $files, 'config'),
        );
    }

    /**
     * @return list<FileMapping>
     */
    private function fileMappings(string $directory, mixed $mappings): array
    {
        if (!\is_array($mappings) || !array_is_list($mappings)) {
            throw new InvalidRecipeException('The recipe "files" value must be a list.');
        }

        $result = [];

        foreach ($mappings as $mapping) {
            if (!\is_array($mapping)) {
                throw new InvalidRecipeException('Each recipe file mapping must be a mapping.');
            }

            $this->assertKeys($mapping, ['source', 'target', 'overwrite'], 'file mapping');
            $target = $mapping['target'] ?? null;
            $overwrite = $mapping['overwrite'] ?? false;

            if (!\is_string($target) || !\is_bool($overwrite)) {
                throw new InvalidRecipeException('A recipe file mapping needs a string target and an optional boolean overwrite value.');
            }

            $result[] = new FileMapping($this->path($directory, $mapping['source'] ?? null, 'files.source'), $target, $overwrite);
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function paths(string $directory, mixed $files, string $key): array
    {
        if (!\is_array($files) || !array_is_list($files)) {
            throw new InvalidRecipeException(\sprintf('The recipe "%s" value must be a list.', $key));
        }

        return array_map(fn (mixed $file) => $this->filePath($directory, $file, $key), $files);
    }

    private function path(string $directory, mixed $relativePath, string $key): string
    {
        if (!\is_string($relativePath) || '' === $relativePath || str_contains($relativePath, "\0")) {
            throw new InvalidRecipeException(\sprintf('The recipe "%s" path must be a non-empty string.', $key));
        }

        $path = Path::canonicalize(Path::join($directory, $relativePath));

        if (!Path::isBasePath($directory, $path) || !file_exists($path)) {
            throw new InvalidRecipeException(\sprintf('The recipe "%s" path "%s" is invalid.', $key, $relativePath));
        }

        return $path;
    }

    private function filePath(string $directory, mixed $relativePath, string $key): string
    {
        $path = $this->path($directory, $relativePath, $key);

        if (!is_file($path)) {
            throw new InvalidRecipeException(\sprintf('The recipe "%s" path "%s" is not a file.', $key, $relativePath));
        }

        return $path;
    }

    /**
     * @param array<array-key, mixed> $values
     * @param list<string>            $allowedKeys
     */
    private function assertKeys(array $values, array $allowedKeys, string $context): void
    {
        $unknown = array_diff(array_keys($values), $allowedKeys);

        if ([] !== $unknown) {
            throw new InvalidRecipeException(\sprintf('The recipe %s contains unsupported keys: %s.', $context, implode(', ', $unknown)));
        }
    }
}
