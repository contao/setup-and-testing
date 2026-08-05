<?php

declare(strict_types=1);

namespace Contao\E2eTestBundle\Installation;

use Contao\E2eTestBundle\ManagedEdition\ManagedEditionConfig;
use Contao\InstallationRecipe\Configuration\ConfigInstaller;
use Contao\InstallationRecipe\File\FileInstaller;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final readonly class ApplicationPreparer
{
    public function __construct(
        private ConfigInstaller $configInstaller = new ConfigInstaller(),
        private FileInstaller $fileInstaller = new FileInstaller(),
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function prepare(ManagedEditionConfig $config, string $directory, InstallationManifest|null $manifest): void
    {
        $this->removePreviousRecipe($directory, $manifest);
        $recipe = $config->recipe;
        $this->configInstaller->install($recipe->assets->configFragments, $directory);
        $this->fileInstaller->install($recipe->assets->fileMappings, $directory);
    }

    private function removePreviousRecipe(string $directory, InstallationManifest|null $manifest): void
    {
        $paths = [
            Path::join($directory, 'config/config.yaml'),
            Path::join($directory, 'config/contao-e2e'),
            Path::join($directory, 'var/cache'),
        ];

        foreach (!$manifest ? [] : $manifest->mappedTargets as $target) {
            $paths[] = Path::join($directory, $target);
        }

        $this->filesystem->remove($paths);
    }
}
