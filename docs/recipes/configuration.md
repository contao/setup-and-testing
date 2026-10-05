# Dependencies, configuration and files

The `InstallationRecipe` object describes the Composer packages, configuration, fixtures and files for a Managed Edition. Construct it directly in your trusted test setup. See [Build a reusable recipe](../guides/build-recipe.md) for a complete walkthrough.

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

`InstallationRecipe` is the immutable configuration object used by Managed Edition tests. Its `with…()` methods return new recipes. Configuration fragments and fixture files must exist when the recipe is constructed.

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
