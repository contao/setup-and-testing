<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Composer;

use Composer\Config;
use Composer\IO\NullIO;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryFactory;
use Contao\E2eTesting\Exception\E2eTestException;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Composer\PathRepositoryPaths;
use Symfony\Component\Filesystem\Path;

final class ComposerPathRepositories
{
    public function metadata(ComposerConfig $config, string $directory): PathPackageMetadata
    {
        $repositories = $config->toArray($directory)['repositories'] ?? [];
        $repositories = (new PathRepositoryPaths())->resolve(\is_array($repositories) ? $repositories : [], $directory);

        $directories = [];
        $copiedDirectories = [];
        $versions = [];

        foreach ($repositories as $repository) {
            if (!$this->isPathRepository($repository)) {
                continue;
            }

            foreach ($this->packages($repository) as $package) {
                $url = $package->getDistUrl();
                if (null === $url) {
                    continue;
                }

                $directory = Path::canonicalize($url);
                $directories[$directory] = $directory;
                $versions[$package->getName().'@'.$package->getPrettyVersion()] = $package->getVersion();
                if (true !== ($repository['options']['symlink'] ?? null)) {
                    $copiedDirectories[$directory] = $directory;
                }
            }
        }

        ksort($directories);
        ksort($copiedDirectories);
        ksort($versions);

        return new PathPackageMetadata(array_values($directories), array_values($copiedDirectories), $versions);
    }

    /**
     * @param array<string, mixed> $repository
     *
     * @return list<PackageInterface>
     */
    private function packages(array $repository): array
    {
        try {
            $manager = RepositoryFactory::manager(new NullIO(), new Config(false));

            return array_values($manager->createRepository('path', $repository)->getPackages());
        } catch (\Exception $exception) {
            throw new E2eTestException(\sprintf('Cannot read the Composer path repository "%s": %s', $repository['url'], $exception->getMessage()), 0, $exception);
        }
    }

    /**
     * @phpstan-assert-if-true array{type: 'path', url: string, options?: mixed} $repository
     */
    private function isPathRepository(mixed $repository): bool
    {
        return \is_array($repository) && 'path' === ($repository['type'] ?? null) && \is_string($repository['url'] ?? null);
    }
}
