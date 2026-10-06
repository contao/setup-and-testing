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
use Contao\InstallationRecipe\Cache\InMemoryCache;
use Contao\InstallationRecipe\Fixture\FixtureLoader;
use Contao\InstallationRecipe\Fixture\FixtureParser;
use Contao\InstallationRecipe\Fixture\FixtureValueResolver;

$cache = new InMemoryCache();
$loader = new FixtureLoader(new FixtureParser($cache), new FixtureValueResolver(), $cache);
$result = $loader->load($connection, $recipe->fixtures);
$pageId = $result->value('regular');
$alias = $result->value('regular', 'alias');
$url = $result->interpolate('/pages/{regular}/{regular->alias}');
```

After loading fixtures, `$loader->result($connection)` returns the current `FixtureResult` without querying the database or loading fixtures again. Results are retained separately for each live connection. A new load replaces that connection's result, and a failed load or explicit invalidation makes it unavailable until a successful load. In an e2e test, `self::managedEdition()->database()->fixtures()` delegates to this accessor and includes the initial fixture load. After applying an archive, read the result through `$result->fixtures`, as shown in [Apply a recipe](../guides/apply-recipe.md).

## Cache invalidation

Parsed fixtures are cached by content, so file changes are picked up automatically. Table identities are cached per live database connection. When changing its database or schema, invalidate the connection's cached values before loading fixtures again:

```php
$loader->invalidateCache($connection);
// Apply schema changes before loading fixtures again.
$loader->load($connection, $fixtures);
```

Recipe installation and managed e2e migrations and database recreation handle this automatically.
