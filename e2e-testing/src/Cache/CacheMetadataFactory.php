<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Cache;

use Composer\InstalledVersions;
use Contao\E2eTesting\Exception\E2eTestException;
use Symfony\Component\Filesystem\Path;

final readonly class CacheMetadataFactory
{
    /**
     * @param array<string, mixed> $compatibilityOverrides
     */
    public function __construct(
        private string|null $playwrightPackageDirectory = null,
        private array $compatibilityOverrides = [],
        private string|null $operatingSystem = null,
        private string|null $architecture = null,
    ) {
    }

    /**
     * @return array{
     *     schema_version: int,
     *     playwright: array{fingerprint: string, path: string},
     *     e2e: array{fingerprint: string, path: string}
     * }
     */
    public function create(CacheConfig $config): array
    {
        $compatibility = array_replace_recursive($this->compatibility(), $this->compatibilityOverrides);

        return [
            'schema_version' => 1,
            'playwright' => [
                'fingerprint' => $this->playwrightFingerprint(),
                'path' => $config->playwrightCacheDirectory(),
            ],
            'e2e' => [
                'fingerprint' => $this->hash($compatibility),
                'path' => $config->e2eCacheDirectory(),
            ],
        ];
    }

    private function playwrightFingerprint(): string
    {
        $playwrightDirectory = realpath(Path::join($this->packageDirectory(), 'bin/node_modules/playwright'));

        if (false === $playwrightDirectory) {
            throw new E2eTestException('Playwright Node dependencies are not prepared. Run "vendor/bin/playwright-install" before "vendor/bin/contao-e2e cache:metadata". This preparation may download Node packages.');
        }

        $coreDirectory = realpath(Path::join(\dirname($playwrightDirectory), 'playwright-core'));

        if (false === $coreDirectory) {
            throw new E2eTestException('Could not locate playwright-core in the prepared Playwright Node dependencies.');
        }

        $version = $this->readVersion(Path::join($playwrightDirectory, 'package.json'));
        $browsers = $this->readBrowsers(Path::join($coreDirectory, 'browsers.json'));
        $platform = [
            'operating_system' => $this->operatingSystem ?? PHP_OS_FAMILY,
            'architecture' => $this->architecture ?? php_uname('m'),
        ];

        return $this->hash([$version, $browsers, $platform]);
    }

    private function packageDirectory(): string
    {
        if (null !== $this->playwrightPackageDirectory) {
            return $this->playwrightPackageDirectory;
        }

        $directory = InstalledVersions::getInstallPath('playwright-php/playwright');

        if (null === $directory) {
            throw new E2eTestException('Could not locate the installed playwright-php/playwright package.');
        }

        return Path::canonicalize($directory);
    }

    private function readVersion(string $path): string
    {
        $package = $this->readJson($path);
        $version = $package['version'] ?? null;

        if (!\is_string($version) || '' === $version) {
            throw new E2eTestException(\sprintf('The installed Playwright package "%s" has no version.', $path));
        }

        return $version;
    }

    /**
     * @return array<string, array{revision: string, revision_overrides: array<string, string>}>
     */
    private function readBrowsers(string $path): array
    {
        $document = $this->readJson($path);
        $entries = $document['browsers'] ?? null;

        if (!\is_array($entries)) {
            throw new E2eTestException(\sprintf('The installed Playwright browser registry "%s" is invalid.', $path));
        }

        $browsers = [];

        foreach ($entries as $entry) {
            if (\is_array($entry) && true === ($entry['installByDefault'] ?? false)) {
                $this->addBrowser($browsers, $entry, $path);
            }
        }

        ksort($browsers);

        return $browsers;
    }

    /**
     * @param array<string, array{revision: string, revision_overrides: array<string, string>}> $browsers
     * @param array<array-key, mixed>                                                           $entry
     */
    private function addBrowser(array &$browsers, array $entry, string $path): void
    {
        $name = $entry['name'] ?? null;
        $revision = $entry['revision'] ?? null;
        $overrides = $entry['revisionOverrides'] ?? [];

        if (!\is_string($name) || !\is_string($revision) || !\is_array($overrides)) {
            throw new E2eTestException(\sprintf('The installed Playwright browser registry "%s" is invalid.', $path));
        }

        $revisionOverrides = [];

        foreach ($overrides as $platform => $override) {
            if (\is_string($platform) && \is_string($override)) {
                $revisionOverrides[$platform] = $override;
            }
        }

        ksort($revisionOverrides);
        $browsers[$name] = ['revision' => $revision, 'revision_overrides' => $revisionOverrides];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            throw new E2eTestException(\sprintf('Could not find the installed Playwright metadata "%s".', $path));
        }

        $contents = file_get_contents($path);

        if (false === $contents) {
            throw new E2eTestException(\sprintf('Could not read the installed Playwright metadata "%s".', $path));
        }

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function compatibility(): array
    {
        return [
            'php' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
            'operating_system' => $this->operatingSystem ?? PHP_OS_FAMILY,
            'architecture' => $this->architecture ?? php_uname('m'),
            'packages' => [
                'contao/e2e-testing' => $this->packageVersion('contao/e2e-testing'),
                'contao/installation-recipe' => $this->packageVersion('contao/installation-recipe'),
            ],
            'composer' => $this->composerConfiguration(),
        ];
    }

    private function packageVersion(string $package): string
    {
        $version = InstalledVersions::getVersion($package);

        if (null !== $version) {
            return $version.'@'.(InstalledVersions::getReference($package) ?? 'unknown');
        }

        $root = InstalledVersions::getRootPackage();

        return ($root['version'] ?? 'unknown').'@'.($root['reference'] ?? 'unknown');
    }

    /**
     * @return array<string, string|null>
     */
    private function composerConfiguration(): array
    {
        $configuration = [];

        foreach ([
            'COMPOSER_IGNORE_PLATFORM_REQ',
            'COMPOSER_IGNORE_PLATFORM_REQS',
            'COMPOSER_MINIMAL_CHANGES',
            'COMPOSER_MIRROR_PATH_REPOS',
            'COMPOSER_PREFER_LOWEST',
            'COMPOSER_PREFER_STABLE',
            'COMPOSER_WITH_ALL_DEPENDENCIES',
            'COMPOSER_WITH_DEPENDENCIES',
        ] as $name) {
            $value = getenv($name);
            $configuration[$name] = false === $value ? null : $value;
        }

        return $configuration;
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', serialize($value));
    }
}
