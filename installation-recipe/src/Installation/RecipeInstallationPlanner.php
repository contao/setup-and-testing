<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\Installation;

use Contao\InstallationRecipe\Exception\InvalidRecipeException;
use Contao\InstallationRecipe\File\FileInstaller;
use Contao\InstallationRecipe\File\FileMapping;
use Contao\InstallationRecipe\File\InstallationPathValidator;
use Contao\InstallationRecipe\File\MappingFileEnumerator;
use Contao\InstallationRecipe\File\PortableFilePolicy;
use Contao\InstallationRecipe\Fixture\FixtureParser;
use Contao\InstallationRecipe\Recipe\PortableInstallationRecipe;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Yaml\Yaml;

final readonly class RecipeInstallationPlanner
{
    public function __construct(
        private PortableFilePolicy $files,
        private InstallationPathValidator $paths,
        private FixtureParser $fixtures,
    ) {
    }

    public function filePolicy(): PortableFilePolicy
    {
        return $this->files;
    }

    public function plan(PortableInstallationRecipe $recipe, string $directory): RecipeInstallationPlan
    {
        $composer = $this->documentDestination($directory, 'composer.json');
        $config = $this->documentDestination($directory, 'config/config.yaml');
        $journal = $this->documentDestination($directory, '.contao-recipes/'.str_replace('/', '--', $recipe->descriptor->name).'.json');
        $this->validateComposer($composer);
        $this->fixtures->parse($recipe->content->fixtures);
        (new FileInstaller())->withPolicy($this->files)->validate($recipe->content->assets->fileMappings, $directory);
        $fragments = array_map(static fn ($fragment) => $fragment->path, $recipe->content->assets->configFragments);
        $changes = [
            'name' => $recipe->descriptor->name,
            'format' => $recipe->descriptor->format,
            'composer' => [
                'destination' => $composer,
                'before' => $this->read($composer),
                'require' => $recipe->dependencies->requirements,
                'require-dev' => $recipe->dependencies->developmentRequirements,
            ],
            'configuration' => [
                'destination' => $config,
                'before' => [] === $fragments ? $this->read($config) : $this->yaml($config, true),
                'fragments' => array_map($this->yaml(...), $fragments),
            ],
            'fixtures' => array_map($this->read(...), $recipe->content->fixtures->files),
            'files' => $this->fileChanges($recipe->content->assets->fileMappings, $directory),
            'journal-destination' => $journal,
            'journal-before' => $this->read($journal),
        ];

        return new RecipeInstallationPlan($recipe, $this->paths->root($directory), $changes);
    }

    private function documentDestination(string $directory, string $target): string
    {
        $destination = $this->paths->validate($directory, $target);
        $this->files->forInstallation($directory)->validateDocumentTarget($target, Path::makeRelative($destination, $this->paths->root($directory)));

        return $destination;
    }

    /**
     * @param list<FileMapping> $mappings
     *
     * @return list<array<string, mixed>>
     */
    private function fileChanges(array $mappings, string $directory): array
    {
        $changes = [];

        foreach ($mappings as $mapping) {
            $this->files->validate($mapping);
            $files = [$mapping->target => $mapping->source, ...(new MappingFileEnumerator())->files($mapping)];

            foreach ($files as $target => $source) {
                $destination = $this->paths->validate($directory, $target);
                $changes[] = [
                    'target' => $target,
                    'resolved-target' => Path::makeRelative($destination, $this->paths->root($directory)),
                    'overwrite' => $mapping->overwrite,
                    'source-sha256' => $this->fingerprint($source),
                    'contents' => $this->fileContents($source),
                    'before' => $this->fingerprint($destination),
                ];
            }
        }

        return $changes;
    }

    /**
     * @return array{encoding: string, value: string}|null
     */
    private function fileContents(string $path): array|null
    {
        if (is_dir($path)) {
            return null;
        }

        $contents = $this->read($path);

        if (null === $contents) {
            throw new InvalidRecipeException('Mapped source files must exist.');
        }

        $text = !str_contains($contents, "\0") && 1 === preg_match('//u', $contents);

        return [
            'encoding' => $text ? 'utf-8' : 'base64',
            'value' => $text ? $contents : base64_encode($contents),
        ];
    }

    private function fingerprint(string $path): string|null
    {
        if (is_dir($path)) {
            return 'directory';
        }

        if (!file_exists($path)) {
            return null;
        }

        $hash = hash_file('sha256', $path);

        if (false === $hash) {
            throw new InvalidRecipeException(\sprintf('Cannot read the installation file "%s".', $path));
        }

        return $hash;
    }

    private function validateComposer(string $path): void
    {
        $contents = $this->read($path);

        if (null === $contents) {
            throw new InvalidRecipeException('The target Composer file must exist.');
        }

        $composer = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (!\is_array($composer)) {
            throw new InvalidRecipeException('The target Composer file must contain an object.');
        }

        foreach (['require', 'require-dev'] as $section) {
            if (isset($composer[$section]) && !\is_array($composer[$section])) {
                throw new InvalidRecipeException(\sprintf('The target Composer "%s" value must be an object.', $section));
            }
        }
    }

    private function yaml(string $path, bool $optional = false): string|null
    {
        $contents = $this->read($path);

        if (null === $contents && !$optional) {
            throw new InvalidRecipeException('The recipe configuration fragment must exist.');
        }

        if (null !== $contents) {
            $values = Yaml::parse($contents);

            if (null !== $values && !\is_array($values)) {
                throw new InvalidRecipeException('Configuration fragments must contain YAML mappings.');
            }
        }

        return $contents;
    }

    private function read(string $path): string|null
    {
        if (!file_exists($path)) {
            return null;
        }

        if (!is_file($path)) {
            throw new InvalidRecipeException('Installation documents must be regular files.');
        }

        $contents = file_get_contents($path);

        if (false === $contents) {
            throw new InvalidRecipeException(\sprintf('Cannot read the installation document "%s".', $path));
        }

        return $contents;
    }
}
