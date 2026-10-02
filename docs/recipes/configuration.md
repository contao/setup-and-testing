# Dependencies, configuration and files

A PHP recipe describes the Composer packages, configuration, fixtures and files for a Managed Edition. Build it in your test configuration or a separate recipe file. See [Build a reusable recipe](../guides/build-recipe.md) for a complete walkthrough.

## Define the recipe

```php
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\File\FileMapping;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;

$composer = ComposerConfig::managedEdition('^5.7')
    ->require('contao/news-bundle', '^5.7')
    ->withPathPackage('acme/example-bundle', __DIR__.'/..', '1.0.x-dev');

$recipe = InstallationRecipe::create($composer)
    ->withConfigFile(__DIR__.'/config.yaml')
    ->withFixtureFile(__DIR__.'/fixtures/pages.yaml')
    ->withFileMapping(new FileMapping(__DIR__.'/files', 'files'));
```

`InstallationRecipe` is the immutable PHP recipe used by Managed Edition tests. Its `with…()` methods return new recipes. Configuration fragments and fixture files must exist when the recipe is constructed.

## Copy files into the installation

`FileMapping` maps an existing source file or directory to a relative destination inside the installation. Existing destination files are protected unless you explicitly enable `overwrite`. Absolute destinations and parent traversal are rejected.

```php
use Contao\InstallationRecipe\File\FileMapping;

$recipe = $recipe->withFileMapping(new FileMapping(
    __DIR__.'/assets',
    'files/example-theme',
    overwrite: true,
));
```

In Managed Editions, files are copied as part of setup. Call `self::managedEdition()->synchronizeFiles('files/example-theme')` if a test needs those files registered in Contao's DBAFS, for example for a file-picker widget. Without a path, synchronization covers the configured filesystem.

Portable archives use the separate `PortableInstallationRecipe` model returned by `RecipeArchive`. See [archive format](archives.md) and [installation behavior](installation.md).
