<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\File;

use Contao\InstallationRecipe\Exception\InvalidRecipeException;
use Symfony\Component\Filesystem\Path;

final readonly class PortableFilePolicy
{
    private const PROTECTED_TARGETS = ['composer.json', 'composer.lock', 'config/config.yaml', 'vendor', '.git', '.contao-recipes', '.contao-e2e'];

    /**
     * @param list<string>|null $allowedTargets
     * @param list<string>      $protectedTargets
     */
    public function __construct(
        public bool $overwrite = false,
        public array|null $allowedTargets = null,
        public array $protectedTargets = self::PROTECTED_TARGETS,
    ) {
        foreach ([...($allowedTargets ?? []), ...$protectedTargets] as $target) {
            if ('' === $target || Path::isAbsolute($target) || Path::canonicalize($target) !== $target || preg_match('#(^|/)\.\.?(?:/|$)#', $target)) {
                throw new InvalidRecipeException('Allowed recipe targets must be canonical relative paths.');
            }
        }
    }

    public function withOverwrite(bool $overwrite): self
    {
        return new self($overwrite, $this->allowedTargets, $this->protectedTargets);
    }

    /**
     * @param list<string> $targets
     */
    public function withAllowedTargets(array $targets): self
    {
        return new self($this->overwrite, $targets, $this->protectedTargets);
    }

    /**
     * @param list<string> $targets
     */
    public function withProtectedTargets(array $targets): self
    {
        return new self($this->overwrite, $this->allowedTargets, $targets);
    }

    public function forInstallation(string $directory): InstallationFilePolicy
    {
        return new InstallationFilePolicy($this, $directory);
    }

    public function validate(FileMapping $mapping): void
    {
        if (Path::canonicalize($mapping->target) !== $mapping->target) {
            throw new InvalidRecipeException('Portable recipe targets must use canonical relative paths.');
        }

        if ($mapping->overwrite && !$this->overwrite) {
            throw new InvalidRecipeException('The host must explicitly allow portable recipe overwrites.');
        }

        $this->validateTarget($mapping->target, is_dir($mapping->source));

        foreach ((new MappingFileEnumerator())->files($mapping) as $target => $source) {
            $this->validateTarget($target, is_dir($source));
        }
    }

    public function assertArchivePath(string $path): void
    {
        if ('recipe.php' === strtolower($path)) {
            throw new InvalidRecipeException('Portable recipes must use recipe.yaml. A root recipe.php is not allowed.');
        }
    }

    public function validateTarget(string $target, bool $directory): void
    {
        $this->assertProtectedTarget($target, $directory);

        if (null !== $this->allowedTargets) {
            foreach ($this->allowedTargets as $allowed) {
                if ($this->matches($target, $allowed)) {
                    return;
                }
            }

            throw new InvalidRecipeException(\sprintf('The host has not allowed the recipe target "%s".', $target));
        }
    }

    public function validateDocumentTarget(string $requested, string $resolved): void
    {
        if ($requested !== $resolved) {
            $this->assertProtectedTarget($resolved, false);
        }
    }

    private function assertProtectedTarget(string $target, bool $directory): void
    {
        foreach ($this->protectedTargets as $protected) {
            if ($this->matches(strtolower($target), strtolower($protected)) || !$directory && str_starts_with(strtolower($protected), strtolower($target).'/')) {
                throw new InvalidRecipeException(\sprintf('The portable recipe target "%s" is protected.', $target));
            }
        }
    }

    private function matches(string $target, string $allowed): bool
    {
        $allowed = rtrim($allowed, '/');

        return $target === $allowed || str_starts_with($target, $allowed.'/');
    }
}
