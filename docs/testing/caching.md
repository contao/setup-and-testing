# Caching and maintenance

Managed Edition tests reuse installations to make later runs faster. Each project stores test data in its ignored `.contao-e2e/` directory.

## What gets reused?

| Change | What happens on the next test run |
| --- | --- |
| No relevant changes | Reuse the installation and restore test fixtures |
| Composer requirements or a linked package's manifest | Resolve dependencies in a fresh installation |
| Package source or application configuration | Rerun application setup and migrations |
| Fixture contents only | Reset and reload the database |

Parallel processes use separate installation and database slots. Source fingerprints are cached for the application runtime. Tests that change source during a run can call `$application->runtime()->cache->clear()` before recalculating them.

The Playwright process and browser engine are reused within each test class in both modes. Browser contexts have independent cookies and storage and are closed between tests. See [browsers and assertions](browsers.md).

## Share an in-memory cache

An application runtime owns a cache that any service can use. PHPUnit application tests use a shared process runtime. Create an explicit runtime to give a group of applications its own cache:

```php
use Contao\E2eTesting\Application\ApplicationConfig;
use Contao\E2eTesting\Application\ApplicationRuntime;

$runtime = ApplicationRuntime::create();
$first = $runtime->createApplication(ApplicationConfig::create('http://localhost:8080'));
$second = $runtime->createApplication(ApplicationConfig::create('http://localhost:8081'));
$runtime->cache->set('custom.value', 'shared');
```

This works with all application configurations, including Managed Edition and local servers. The cache survives application resets and release while the runtime remains in use. Call `$runtime->cache->clear()` to clear its values. Database migrations invalidate only their connection scope, leaving parsed fixtures and source fingerprints available to other applications. Separate runtimes have separate caches.

## Restore database fixtures

Managed Edition tests restore recipe fixtures between tests by default. Call `resetDatabase()` when you need a different fixture set or a fresh database during a test. It returns a `FixtureResult`, whose generated values can be read with `value()` or inserted into strings with `interpolate()`. See [fixture references](../recipes/fixtures.md).

The fixture loader retains its current result per connection. The database manager exposes it through `self::managedEdition()->database()->fixtures()` after initial setup or any database reset. This accessor does not query the database or reload fixtures:

```php
$articleId = self::managedEdition()->database()->fixtures()->value('article');
```

Use the current result in helpers that only need fixture IDs. Runtime-only resets preserve the result. A failed database reset or schema recreation makes the result unavailable, and the accessor throws a `LogicException` until fixtures are loaded successfully again.

For read-only tests with a data provider, `prepareDatabase($fixtures)` reuses an unchanged fixture set while its prepared result remains current. Direct database resets or result invalidation force the next call to reload the requested fixtures. It still clears active browser sessions and mutable runtime caches. Use `resetDatabase()` if a test may have changed database contents.

Existing applications need their own database reset hooks. See [custom PHPUnit integration](phpunit.md).

## Workspace and cleanup

| Location | Contents |
| --- | --- |
| `.contao-e2e/cache/e2e/installations/<fingerprint>/<slot>/project` | Cached Contao installations |
| `.contao-e2e/database/data` | Default Docker database files |
| `.contao-e2e/database/<fingerprint>/data` | Files for additional database images |
| `.contao-e2e/runtime/` | Web server router and origin mapping |
| `.contao-e2e/traces/` | Recorded browser traces |

An existing database server stores database files in its own data directory. `CONTAO_E2E_DIRECTORY` overrides the test workspace. Set `CONTAO_E2E_NO_CACHE=1` to force a fresh Managed Edition dependency installation.

Use `vendor/bin/contao-e2e cache:clear` to clear installation caches and `vendor/bin/contao-e2e database:stop` to stop the project's Docker database variants. Active test leases protect running tests unless you supply `--force`. See [CLI commands](../reference/cli.md).

## Keep PHPUnit's cache in the workspace

Set `cacheDirectory` in your existing `phpunit.xml.dist`:

```xml
<phpunit cacheDirectory=".contao-e2e/cache/phpunit"/>
```

Alternatively, pass `--cache-directory=.contao-e2e/cache/phpunit` to PHPUnit. This keeps disposable test data together.

Xdebug is disabled in Managed Edition setup commands and the web server. See [CI caches](../running/ci.md) to reuse installations and browser binaries between CI runs.
