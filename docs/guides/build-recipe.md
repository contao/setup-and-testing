# Build a reusable recipe

Use a recipe to describe reusable dependencies, Symfony configuration, database fixtures and project files. You can use a PHP recipe in tests or distribute an archive for an installer. Recipe creation does not require PHPUnit or a browser.

## Prerequisites and installation

You need PHP 8.2 or newer, Composer and the ZIP extension. Run in the project that owns the recipe:

```shell
composer require contao/installation-recipe
```

## Create a PHP recipe for tests

Use the following layout in that project:

```text
recipe.php
config/theme.yaml
fixtures/pages.yaml
assets/theme.css
```

Create `config/theme.yaml`:

```yaml
framework:
    default_locale: en
```

Create `fixtures/pages.yaml`:

```yaml
tl_page:
    example_root:
        pid: 0
        type: root
        title: Example site
        alias: example-site
        published: true
    example_page:
        pid: '@example_root'
        type: regular
        title: Example page
        alias: example-page
        published: true
```

Add your stylesheet at `assets/theme.css`. Put this in `recipe.php`:

```php
<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\File\FileMapping;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;

$recipe = InstallationRecipe::create(ComposerConfig::managedEdition('^5.7'))
    ->withConfigFile(__DIR__.'/config/theme.yaml')
    ->withFixtureFile(__DIR__.'/fixtures/pages.yaml')
    ->withFileMapping(new FileMapping(__DIR__.'/assets', 'files/example-theme'));

return $recipe;
```

```shell
php recipe.php
```

A successful run exits without output. The script constructs the recipe and checks that the referenced files exist. It does not install Contao or check that the configuration works in an application. A Managed Edition test can load it with `$recipe = require $root.'/recipe.php'` and pass it to `ManagedEditionConfig::create($recipe, $root)`.

The fixture illustrates related pages. A complete renderable frontend may additionally need a theme, layout, articles and content. Add those according to the application being tested.

## Package a portable recipe

For distribution, author an [archive manifest](../recipes/archives.md) alongside Composer fragments, configuration, fixtures and files. This is a separate portable representation, not an automatic serialization of the PHP testing recipe.

Use [the complete example-theme source](https://github.com/contao/setup-and-testing/tree/main/installation-recipe/examples/example-theme) as a starting point. Use a local copy of that source directory. From `installation-recipe/examples/example-theme/` in the monorepo, run:

```shell
zip -r example-theme.zip recipe.yaml composer.json config fixtures files
```

On Windows, from the same directory:

```powershell
Compress-Archive -Path recipe.yaml, composer.json, config, fixtures, files -DestinationPath example-theme.zip
```

Inspect the archive and confirm `recipe.yaml` is at its root. You now have an archive an installer can open and apply using [Apply a recipe](apply-recipe.md).

Continue with [fixture references](../recipes/fixtures.md), [configuration and files](../recipes/configuration.md) and [the archive format](../recipes/archives.md).
