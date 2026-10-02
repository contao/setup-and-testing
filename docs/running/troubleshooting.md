# Troubleshooting

## The first Managed Edition run is slow

The first run resolves Composer dependencies, builds Contao and prepares the database. Later runs reuse installation caches. Review [cache behavior](../testing/caching.md) before clearing them. Changes to dependency constraints legitimately require a new installation.

## Browser binaries or system libraries are missing

Run `vendor/bin/playwright-install --browsers` after Composer installation. On a fresh Linux runner, use `vendor/bin/playwright-install --with-deps --browsers`. If setting `PLAYWRIGHT_BROWSERS_PATH`, use the same value for installation, cache restoration and tests.

## Docker or the database is unavailable

Verify Docker is running and configured for Linux containers. Alternatively, set `CONTAO_E2E_DATABASE_URL` to a server whose user can create isolated test databases. Set a server URL, not the existing application's database URL. See [databases](../testing/databases.md).

## An existing-application test cannot connect

Verify that the application is reachable from the machine running PHPUnit. If its server is not running, start it with your project's usual command and wait until it is ready. The URL-based test configuration does not start a server. Include any application base path. CI's `localhost` refers to its runner, not your development machine.

## Composer cannot resolve local packages

Check each package name, path and dependency constraint. The version passed to `withPathPackage()` must satisfy constraints from other packages. In a monorepo, inspect the root version or `dev-main` branch alias used by `MonorepoProject`. See [Contao package monorepo tests](../guides/monorepo.md).

## Tests affect one another

Browser context isolation only resets browser cookies and storage. Existing applications need their own server-side resets. Managed Editions restore configured recipe fixtures between tests by default. Use `resetDatabase()` for tests that modify data, and reserve `prepareDatabase()` for read-only fixture reuse. See [isolation](../testing/caching.md).

## A backend action races with a dynamic form

Use [the navigation and AJAX helpers](../testing/backend.md) for Turbo transitions and dynamic palettes. Use locator assertions for updates that do not navigate. Fixed sleeps hide timing problems rather than providing a completion condition.

## Inspect a failing browser test

Enable [traces](traces.md), or set `PW_HEADLESS=false` and optionally `PW_SLOWMO_MS` to watch a local run. Follow the trace path printed by PHPUnit rather than guessing the filename. [Playwright options](playwright.md) explains timeouts.

## Interrupted tests leave a database container running

Run `vendor/bin/contao-e2e database:stop` from the consumer project. It refuses to stop databases with active test leases unless `--force` is supplied. See [CLI commands](../reference/cli.md).
