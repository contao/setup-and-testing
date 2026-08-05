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
use Symfony\Component\Filesystem\Path;

final readonly class RecipeInstaller
{
    public function __construct(
        private InstallationDocumentInstallers $documents = new InstallationDocumentInstallers(),
        private InstallationContentInstallers $content = new InstallationContentInstallers(),
    ) {
    }

    public function install(PortableInstallationRecipe $recipe, InstallationTarget $target): RecipeInstallationResult
    {
        $assets = $recipe->content->assets;
        $this->content->files->validate($assets->fileMappings, $target->directory);
        $composer = $this->documents->composer->merge(
            $recipe->dependencies,
            Path::join($target->directory, 'composer.json'),
        );
        $configurationChanged = $this->documents->config->merge($assets->configFragments, $target->directory);

        if ($composer->changed) {
            $target->runtime->installDependencies($target->directory);
        }

        $target->runtime->migrate($target->directory);
        $fixtures = $this->content->fixtures->load($target->connection, $recipe->content->fixtures);
        $this->content->files->install($assets->fileMappings, $target->directory);
        $result = new RecipeInstallationResult($composer, $fixtures, $configurationChanged);
        $this->documents->journal->write($recipe, $result, $target->directory);

        return $result;
    }
}
