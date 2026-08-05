<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\Composer;

use Contao\InstallationRecipe\Exception\InvalidRecipeException;
use Symfony\Component\Filesystem\Filesystem;

final readonly class ComposerMerger
{
    public function __construct(private Filesystem $filesystem = new Filesystem())
    {
    }

    public function merge(ComposerDependencies $dependencies, string $composerFile): ComposerMergeResult
    {
        $composer = $this->read($composerFile);
        $original = $composer;
        $requirements = $this->mergeSection($composer, 'require', $dependencies->requirements);
        $developmentRequirements = $this->mergeSection($composer, 'require-dev', $dependencies->developmentRequirements);

        if ($composer !== $original) {
            $this->filesystem->dumpFile(
                $composerFile,
                json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
            );
        }

        return new ComposerMergeResult($requirements, $developmentRequirements, $composer !== $original);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $composerFile): array
    {
        if (!is_file($composerFile)) {
            throw new InvalidRecipeException(\sprintf('The target Composer file "%s" does not exist.', $composerFile));
        }

        try {
            $composer = json_decode((string) file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidRecipeException(\sprintf('The target Composer file "%s" is invalid: %s', $composerFile, $exception->getMessage()), 0, $exception);
        }

        if (!\is_array($composer)) {
            throw new InvalidRecipeException(\sprintf('The target Composer file "%s" must contain a JSON object.', $composerFile));
        }

        return $composer;
    }

    /**
     * @param array<string, mixed>  $composer
     * @param array<string, string> $requirements
     *
     * @return array<string, string|null>
     */
    private function mergeSection(array &$composer, string $section, array $requirements): array
    {
        if ([] === $requirements) {
            return [];
        }

        if (isset($composer[$section]) && !\is_array($composer[$section])) {
            throw new InvalidRecipeException(\sprintf('The target Composer "%s" value must be an object.', $section));
        }

        $previous = [];

        foreach ($requirements as $package => $constraint) {
            $value = $composer[$section][$package] ?? null;
            $previous[$package] = \is_string($value) ? $value : null;
            $composer[$section][$package] = $constraint;
        }

        ksort($composer[$section]);

        return $previous;
    }
}
