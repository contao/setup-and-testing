# Contao installation recipes

`contao/installation-recipe` describes and applies portable installation input without depending on a Contao bundle. A recipe combines a Composer project, ordered Symfony configuration fragments, YAML database fixtures, and files copied into the installation.

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

Fixture files map table names to rows. Anonymous row lists remain supported, but named rows can reference each other with `@name`. The loader resolves dependencies across tables and fixture files, including forward references. It lets the database assign auto-increment values and substitutes the actual generated identifier wherever the fixture is referenced.

```yaml
tl_page:
  root:
    pid: 0
    type: root
    title: Example
    alias: example
  regular:
    pid: '@root'
    type: regular
    title: Child page
    alias: child-page
```

Use `@fixture->column` to reference another resolved column and `\@value` for a literal string beginning with `@`. YAML arrays are resolved recursively and stored as PHP-serialized values by default, which allows Contao multi-value fields to contain generated fixture IDs. Prefix a value with `!json` when a column, including a virtual field's storage column, expects JSON instead:

```yaml
tl_example:
  child:
    options: !json
      parent: '@root'
      enabled: true
```

References inside JSON values are resolved before encoding. Fixture names are global to a recipe and must be unique. Missing or circular dependencies abort the complete fixture transaction, so partially imported data is never left behind.

Rows without names use the original list syntax and can still provide explicit IDs when importing legacy fixtures. Named fixtures should normally omit auto-increment columns; this makes recipes safe to apply to existing databases whose next ID is not known in advance.

The low-level loader returns the generated values when an importer needs them:

```php
use Contao\InstallationRecipe\Fixture\FixtureLoader;

$result = new FixtureLoader()->load($connection, $recipe->fixtures);
$pageId = $result->value('regular');
$url = $result->interpolate('/pages/{regular}/{regular->alias}');
```

The package is intentionally independent of `contao/core-bundle`, Panther, and PHPUnit so the same recipe model can later power an installation or theme importer.

## Portable recipe archives

A recipe can be distributed as a ZIP file with this layout:

```text
example-theme.zip
├── recipe.yaml
├── composer.json
├── config/
│   └── theme.yaml
├── fixtures/
│   └── pages.yaml
└── files/
    └── files/example-theme/theme.css
```

`recipe.yaml` is a versioned manifest. All paths are relative to the archive root:

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

The optional `composer.json` is deliberately a fragment rather than a complete Composer project. It may only contain `require` and `require-dev`, preventing a recipe from replacing project metadata or injecting Composer scripts:

```json
{
    "require": {
        "acme/theme-bundle": "^1.0",
        "contao/news-bundle": "^5.3 || ^5.7 || ^6.0"
    }
}
```

Open the archive while it is being installed. Closing it removes the securely extracted temporary directory; the destructor also provides a fallback cleanup. Absolute paths, path traversal, symbolic links, oversized archives, unknown manifest keys, and missing files are rejected before installation.

```php
use Contao\InstallationRecipe\Archive\RecipeArchive;
use Contao\InstallationRecipe\Installation\InstallationTarget;
use Contao\InstallationRecipe\Installation\RecipeInstaller;

$archive = RecipeArchive::open(__DIR__.'/example-theme.zip');
$target = new InstallationTarget($projectDirectory, $connection, $runtime);
$result = (new RecipeInstaller())->install($archive->recipe, $target);
$archive->close();
```

The application supplies an `InstallationRuntimeInterface`. Its `installDependencies()` implementation runs Composer and `migrate()` invokes the application's migration runner. This keeps the recipe package independent of Contao and Symfony Console while allowing a Contao Manager plugin or command to provide those operations.

Installation merges requirements into the existing `composer.json`, recursively merges configuration fragments into `config/config.yaml`, installs dependencies when Composer changed, migrates the database, loads fixtures transactionally, and copies files. A journal is written to `.contao-recipes/<vendor>--<recipe>.json` so later update and removal tooling can identify what the recipe changed.

A complete source tree is available in [`examples/example-theme`](examples/example-theme/). Package it from inside that directory so `recipe.yaml` remains at the ZIP root:

```shell
cd examples/example-theme
zip -r example-theme.zip recipe.yaml composer.json config fixtures files
```
