<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationRecipe\Configuration;

use Contao\InstallationRecipe\Exception\InvalidRecipeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Yaml\Yaml;

final readonly class ConfigInstaller
{
    public function __construct(private Filesystem $filesystem = new Filesystem())
    {
    }

    /**
     * @param list<ConfigFragment> $fragments
     */
    public function install(array $fragments, string $targetDirectory, string|null $baseConfig = null): void
    {
        if ([] === $fragments && null === $baseConfig) {
            return;
        }

        if (null !== $baseConfig && !is_file($baseConfig)) {
            throw new InvalidRecipeException(\sprintf('The base configuration file "%s" does not exist.', $baseConfig));
        }

        $configFile = Path::join($targetDirectory, 'config/config.yaml');

        if ($this->filesystem->exists($configFile)) {
            throw new InvalidRecipeException(\sprintf('The configuration file "%s" already exists.', $configFile));
        }

        $imports = null === $baseConfig ? [] : [['resource' => $baseConfig]];

        foreach ($fragments as $index => $fragment) {
            $name = \sprintf('%02d-%s', $index, basename($fragment->path));
            $destination = Path::join($targetDirectory, 'config/contao-e2e', $name);
            $this->filesystem->copy($fragment->path, $destination);
            $imports[] = ['resource' => 'contao-e2e/'.$name];
        }

        $this->filesystem->dumpFile($configFile, Yaml::dump(['imports' => $imports], 4));
    }
}
