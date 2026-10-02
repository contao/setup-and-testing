# Database fixtures

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

## Reference other rows

Use `@fixture->column` to reference another resolved column and `\@value` for a literal string beginning with `@`. YAML arrays are resolved recursively and stored as PHP-serialized values by default, which allows Contao multi-value fields to contain generated fixture IDs. Prefix a value with `!json` when a column, including a virtual field's storage column, expects JSON instead:

```yaml
tl_example:
  child:
    options: !json
      parent: '@root'
      enabled: true
```

References inside JSON values are resolved before encoding. Fixture names are global to a recipe and must be unique. Missing or circular dependencies abort the complete fixture transaction, so partially imported data is never left behind.

Rows without names use the original list syntax and can still provide explicit IDs when importing legacy fixtures. Named fixtures should normally omit auto-increment columns. This makes recipes safe to apply to existing databases whose next ID is not known in advance.

## Read generated values

Given a DBAL `$connection` and a PHP `$recipe`, the low-level loader returns the values generated during import:

```php
use Contao\InstallationRecipe\Fixture\FixtureLoader;

$result = (new FixtureLoader())->load($connection, $recipe->fixtures);
$pageId = $result->value('regular');
$alias = $result->value('regular', 'alias');
$url = $result->interpolate('/pages/{regular}/{regular->alias}');
```

In a Managed Edition test, `resetDatabase()` also returns a `FixtureResult`. After applying an archive, read the result through `$result->fixtures`, as shown in [Apply a recipe](../guides/apply-recipe.md).
