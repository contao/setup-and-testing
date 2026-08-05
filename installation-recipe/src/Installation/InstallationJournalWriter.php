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

use Contao\InstallationRecipe\Recipe\PortableInstallationRecipe;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final readonly class InstallationJournalWriter
{
    public function __construct(private Filesystem $filesystem = new Filesystem())
    {
    }

    public function write(PortableInstallationRecipe $recipe, RecipeInstallationResult $result, string $targetDirectory): string
    {
        $name = str_replace('/', '--', $recipe->descriptor->name);
        $path = Path::join($targetDirectory, '.contao-recipes', $name.'.json');
        $data = [
            'format' => $recipe->descriptor->format,
            'name' => $recipe->descriptor->name,
            'installed-at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'composer' => [
                'require' => $result->composer->requirements,
                'require-dev' => $result->composer->developmentRequirements,
            ],
            'configuration-changed' => $result->configurationChanged,
            'files' => array_map(static fn ($mapping) => $mapping->target, $recipe->content->assets->fileMappings),
        ];
        $this->filesystem->dumpFile(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );

        return $path;
    }
}
