# Caching and maintenance

Managed Edition tests reuse installations to make later runs faster. Each project stores test data in its ignored `.contao-e2e/` directory.

## What gets reused?

| Change | What happens on the next test run |
| --- | --- |
| No relevant changes | Reuse the installation and restore test fixtures |
| Composer requirements or a linked package's manifest | Resolve dependencies in a fresh installation |
| Package source or application configuration | Rerun application setup and migrations |
| Fixture contents only | Reset and reload the database |

Parallel processes use separate installation and database slots. Source fingerprints are cached for the PHPUnit process, so edit package code between runs. Specialized tests that change source during a run can call `ProcessCachedSourceFingerprint::reset()`.

The Playwright process and browser engine are reused within each test class in both modes. Browser contexts have independent cookies and storage and are closed between tests. See [browsers and assertions](browsers.md).

## Restore database fixtures

Managed Edition tests restore recipe fixtures between tests by default. Call `resetDatabase()` when you need a different fixture set or a fresh database during a test. It returns a `FixtureResult`, whose generated values can be read with `value()` or inserted into strings with `interpolate()`. See [fixture references](../recipes/fixtures.md).

For read-only tests with a data provider, `prepareDatabase($fixtures)` reuses an unchanged fixture set. It still clears active browser sessions and mutable runtime caches. Use `resetDatabase()` if a test may have changed database contents.

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
