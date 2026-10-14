# Dependencies, configuration and files

The `InstallationRecipe` object describes the Composer packages, configuration, fixtures and files for a Managed Edition. Construct it directly in your trusted test setup. See [Build a reusable recipe](../guides/build-recipe.md) for a complete walkthrough.

## Define the recipe

```php
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\File\FileMapping;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;

$composer = ComposerConfig::managedEdition('^5.7')
    ->withOverrides([
        'require' => ['contao/news-bundle' => '^5.7', 'acme/example-bundle' => '1.0.x-dev'],
        'repositories' => [[
            'type' => 'path',
            'url' => __DIR__.'/..',
            'options' => ['symlink' => true, 'versions' => ['acme/example-bundle' => '1.0.x-dev']],
        ]],
    ]);

$recipe = InstallationRecipe::create($composer)
    ->withConfigFile(__DIR__.'/config.yaml')
    ->withFixtureFile(__DIR__.'/fixtures/pages.yaml')
    ->withFileMapping(new FileMapping(__DIR__.'/files', 'files'));
```

`InstallationRecipe` is the immutable configuration object used by Managed Edition tests. Its `with…()` methods return new recipes. Configuration fragments and fixture files must exist when the recipe is constructed.

## Override Composer configuration

`ComposerConfig::withOverrides()` returns a clone with the supplied overrides merged into the complete Composer project configuration. Associative arrays merge recursively, lists are replaced, and explicit `false` values are preserved. Existing requirements remain available unless explicitly replaced.

The recipe package stores and exports Composer configuration without depending on `composer/composer`. Path-package tracking belongs to `e2e-testing`, which uses that library for package metadata, repository filters, wildcard paths and brace patterns. Leading environment variables and home-directory markers in repository paths are expanded when configuring the recipe. Dependency installation still uses the configured Composer executable. Replacing the repository list also replaces the tracked paths. Relative repository paths resolve from the loaded Composer file’s directory, or the working directory when creating a Managed Edition configuration in PHP. Exported paths are made relative to the installation directory.

```php
$composer = $composer->withOverrides([
    'config' => ['allow-plugins' => ['vendor/example-plugin' => true]],
    'autoload' => ['psr-4' => ['App\\' => 'src/']],
    'extra' => ['runtime' => ['class' => 'App\\Runtime']],
]);

$recipe = $recipe->withComposer($composer)
    ->withFileMapping(new FileMapping(__DIR__.'/src', 'src'));
```

Recipe configuration and mapped files are copied before Composer runs, making bootstrap classes available during installation and autoload generation. Autoload files are regenerated when the application changes, so new classmap entries become available in cached installations. Ordinary Composer scripts are disabled, while Composer plugin autoload hooks still run. Contao setup runs separately after the database is created. Failed Contao setup or migrations remain pending and are retried on the next run.

Path repositories configured with `symlink: true` refresh the application after source changes without rebuilding dependencies. Repositories that allow copying, with `symlink: false` or no explicit symlink setting, also rebuild dependencies when their sources change, so copied vendor packages stay current. Changes to Composer’s resolved package versions also rebuild dependencies, including versions inferred from Git branches or tags.

## Set process environment variables

Use `ManagedEditionConfig::withEnvironmentVariables()` to supply environment overrides to console commands and the HTTP server. Overrides take precedence over the generated defaults. A `false` value removes a variable from the child process environment. The test process environment stays unchanged.

```php
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;

$config = ManagedEditionConfig::create($recipe, dirname(__DIR__))
    ->withEnvironmentVariables([
        'APP_SECRET' => 'test-secret',
        'EXAMPLE_OPTION' => 'enabled',
        'DISABLE_HTTP_CACHE' => false,
    ]);
```

## Copy files into the installation

`FileMapping` maps an existing source file or directory to a relative destination inside the installation. Existing destination files are protected unless you explicitly enable `overwrite`. Absolute destinations and parent traversal are rejected. Destination symlinks are allowed when their resolved paths stay inside the installation and satisfy the host policy. Source directories must not contain symbolic links.

```php
use Contao\InstallationRecipe\File\FileMapping;

$recipe = $recipe->withFileMapping(new FileMapping(
    __DIR__.'/assets',
    'files/example-theme',
    overwrite: true,
));
```

In Managed Editions, files are copied as part of setup. Call `self::managedEdition()->synchronizeFiles('files/example-theme')` if a test needs those files registered in Contao's DBAFS, for example for a file-picker widget. Without a path, synchronization covers the configured filesystem.

`RecipeArchive` loads portable archives as `PortableInstallationRecipe` objects. Their file mappings support application files, including PHP DCA and Symfony service configuration. The host controls allowed destinations, protected paths and overwrite permissions. See [the archive format](archives.md) and [installation behavior](installation.md).
