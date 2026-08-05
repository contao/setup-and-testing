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

final readonly class ComposerDependencies
{
    /**
     * @param array<string, string> $requirements
     * @param array<string, string> $developmentRequirements
     */
    public function __construct(
        public array $requirements = [],
        public array $developmentRequirements = [],
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new InvalidRecipeException(\sprintf('The Composer fragment "%s" does not exist.', $path));
        }

        try {
            $contents = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidRecipeException(\sprintf('The Composer fragment "%s" is invalid: %s', $path, $exception->getMessage()), 0, $exception);
        }

        if (!\is_array($contents)) {
            throw new InvalidRecipeException(\sprintf('The Composer fragment "%s" must contain a JSON object.', $path));
        }

        $allowedKeys = ['require', 'require-dev'];

        if ([] !== array_diff(array_keys($contents), $allowedKeys)) {
            throw new InvalidRecipeException('A Composer fragment may only contain "require" and "require-dev".');
        }

        return new self(
            self::requirements($contents['require'] ?? [], 'require'),
            self::requirements($contents['require-dev'] ?? [], 'require-dev'),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function requirements(mixed $requirements, string $key): array
    {
        if (!\is_array($requirements)) {
            throw new InvalidRecipeException(\sprintf('The Composer "%s" value must be an object.', $key));
        }

        foreach ($requirements as $package => $constraint) {
            if (!\is_string($package) || '' === $package || !\is_string($constraint) || '' === $constraint) {
                throw new InvalidRecipeException(\sprintf('The Composer "%s" value must map package names to constraints.', $key));
            }
        }

        return $requirements;
    }
}
