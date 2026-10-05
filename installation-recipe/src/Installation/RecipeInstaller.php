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
use Contao\InstallationRecipe\Recipe\PortableInstallationRecipe;
use Symfony\Component\Filesystem\Path;

final readonly class RecipeInstaller
{
    public function __construct(
        private InstallationDocumentInstallers $documents = new InstallationDocumentInstallers(),
        private InstallationContentInstallers $content = new InstallationContentInstallers(),
        private RecipeInstallationPlanner $planner = new RecipeInstallationPlanner(),
    ) {
    }

    public function plan(PortableInstallationRecipe $recipe, InstallationTarget $target): RecipeInstallationPlan
    {
        return $this->planner->plan($recipe, $target->directory);
    }

    public function install(RecipeInstallationPlan $plan, InstallationTarget $target): RecipeInstallationResult
    {
        $current = $this->plan($plan->recipe, $target);

        if ($current->targetDirectory !== $plan->targetDirectory || $current->changes !== $plan->changes) {
            throw new InvalidRecipeException('The recipe or target changed after review. Create and review a new installation plan.');
        }

        $recipe = $plan->recipe;
        $assets = $recipe->content->assets;
        $this->content->files->withPolicy($this->planner->filePolicy())->validate($assets->fileMappings, $target->directory);
        $composer = $this->documents->composer->withPolicy($this->planner->filePolicy())->merge(
            $recipe->dependencies,
            Path::join($target->directory, 'composer.json'),
        );
        $configurationChanged = $this->documents->config->withPolicy($this->planner->filePolicy())->merge($assets->configFragments, $target->directory);

        if ($composer->changed) {
            $target->runtime->installDependencies($target->directory);
        }

        $target->runtime->migrate($target->directory);
        $fixtures = $this->content->fixtures->load($target->connection, $recipe->content->fixtures);
        $this->content->files->withPolicy($this->planner->filePolicy())->install($assets->fileMappings, $target->directory);
        $result = new RecipeInstallationResult($composer, $fixtures, $configurationChanged);
        $this->documents->journal->withPolicy($this->planner->filePolicy())->write($recipe, $result, $target->directory);

        return $result;
    }
}
