# Build a reusable recipe

Use a portable recipe to describe reusable dependencies, Symfony configuration, database fixtures and application files. A ZIP archive contains a YAML manifest and the files it references.

## Create the archive source

Use [the complete example-theme source](https://github.com/contao/setup-and-testing/tree/main/installation-recipe/examples/example-theme) as a starting point. Its layout is:

```text
recipe.yaml
composer.json
config/theme.yaml
fixtures/pages.yaml
files/files/example-theme/theme.css
```

Define `recipe.yaml`:

```yaml
format: 1
name: acme/example-theme
composer: composer.json
config:
    - config/theme.yaml
fixtures:
    - fixtures/pages.yaml
files:
    - source: files/files/example-theme
      target: files/example-theme
      overwrite: false
```

The Composer fragment may only contain `require` and `require-dev`. Configuration fragments and fixtures use YAML. File mappings can install assets, DCA files, Symfony service configuration, templates and other application files, including PHP.

## Package a portable recipe

From the source directory, run:

```shell
zip -r example-theme.zip recipe.yaml composer.json config fixtures files
```

On Windows:

```powershell
Compress-Archive -Path recipe.yaml, composer.json, config, fixtures, files -DestinationPath example-theme.zip
```

Inspect the archive and confirm `recipe.yaml` is at its root. Include the manifest and its referenced files.

An installer can open, review and apply the archive using [Apply a recipe](apply-recipe.md). Only install recipes from publishers you trust. Dependencies, configuration and fixture data can affect application behavior even though the manifest is declarative.

## Use the object API in tests

Local Managed Edition tests can construct an `InstallationRecipe` directly in their PHP test setup:

```php
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\File\FileMapping;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;

$recipe = InstallationRecipe::create(ComposerConfig::managedEdition('^5.7'))
    ->withConfigFile(__DIR__.'/config/theme.yaml')
    ->withFixtureFile(__DIR__.'/fixtures/pages.yaml')
    ->withFileMapping(new FileMapping(__DIR__.'/assets', 'files/example-theme'));
```

Pass the object to `ManagedEditionConfig::create($recipe, $root)` in the test setup.

Continue with [fixture references](../recipes/fixtures.md), [configuration and files](../recipes/configuration.md) and [the archive format](../recipes/archives.md).
