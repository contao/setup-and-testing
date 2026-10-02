# GitHub Actions and CI caches

## Run Managed Edition tests

This job runs your Contao extension or Contao package monorepo tests in an isolated Contao installation. Install the PHP extensions your application requires and Node.js 20 or newer before preparing Playwright. On a fresh Linux runner, install Playwright system libraries with `vendor/bin/playwright-install --with-deps --browsers`.

### Prepare the cache keys

`cache:metadata` writes separate portable keys for Playwright browser binaries and reusable E2E setup data, then prints each opaque fingerprint and cache root as JSON:

```shell
vendor/bin/contao-e2e cache:metadata
```

The keys are written to `.contao-e2e/cache-keys/playwright` and `.contao-e2e/cache-keys/e2e`. Any CI system can use their contents directly or hash the files. They remain separate because browser binaries and the rest of the E2E setup have different invalidation rules.

The Playwright fingerprint uses the concrete version from the installed Node package, the browser revisions from Playwright's installed browser registry, operating system, OS release or Linux distribution version, and architecture. The E2E fingerprint covers the PHP major and minor version, operating system, architecture, the installed `contao/e2e-testing` and `contao/installation-recipe` versions, and Composer settings that can affect dependency resolution.

The Playwright PHP package resolves the semver constraint in its bundled `package.json` through npm, pnpm, or Yarn. Its resolved Node package and browser registry must therefore exist before metadata can be calculated. Prepare those dependencies explicitly after Composer installation:

```shell
vendor/bin/playwright-install
vendor/bin/contao-e2e cache:metadata
```

The first command may access the network to install Node packages. It does not install browser binaries without `--browsers`. `cache:metadata` never performs this preparation or accesses the network itself.

### Configure the job

A complete GitHub Actions job can keep every cache payload under `.contao-e2e/cache` and restore both groups independently:

```yaml
jobs:
    e2e:
        runs-on: ubuntu-latest
        env:
            PLAYWRIGHT_BROWSERS_PATH: ${{ github.workspace }}/.contao-e2e/cache/playwright
        steps:
            - uses: actions/checkout@v6

            - uses: shivammathur/setup-php@v2
              with:
                  php-version: '8.4'
                  extensions: intl, mbstring, pdo_mysql, zip
                  coverage: none

            - uses: actions/setup-node@v6
              with:
                  node-version: '22'

            - name: Install Composer dependencies
              run: composer install --no-interaction --no-progress

            - name: Prepare Playwright Node dependencies
              run: vendor/bin/playwright-install

            - name: Calculate E2E cache metadata
              run: vendor/bin/contao-e2e cache:metadata

            - name: Restore Playwright browsers
              uses: actions/cache@v5
              with:
                  path: .contao-e2e/cache/playwright
                  key: playwright-${{ hashFiles('.contao-e2e/cache-keys/playwright') }}

            - name: Restore E2E setup cache
              uses: actions/cache@v5
              with:
                  path: .contao-e2e/cache/e2e
                  key: contao-e2e-${{ hashFiles('.contao-e2e/cache-keys/e2e') }}

            - name: Install and verify Playwright browsers
              run: vendor/bin/playwright-install --with-deps --browsers

            - name: Run PHPUnit
              run: vendor/bin/phpunit --configuration=phpunit.xml.dist
```

The cache root contains separate `playwright` and `e2e` groups. The package owns the contents of each group, so adding another reusable E2E setup cache does not require consuming projects to update their CI configuration. Database data, process locks, runtime files, and failure artifacts are deliberately excluded. The existing per-installation dependency and application fingerprints still validate restored installations, so project source files do not need to be part of the outer CI cache key.

GitHub Actions restricts cache access by branch and ref. A pull request can restore caches created on its base branch, while caches created for a pull request's merge ref are only available to reruns of that pull request. Run this job on pushes to the default branch as well as pull requests so the default branch regularly creates a cache that different pull requests can reuse.

## Test an existing application

Use the same PHP, Node.js and Playwright installation steps. Restore the Playwright cache if desired, and omit the Managed Edition setup cache.

Install and build your application and prepare its test data before PHPUnit runs.

With `LocalApplicationConfig`, PHPUnit starts and stops the server itself. Install the runtime used by its configured command on the runner.

With `ApplicationConfig`, start the application if needed, wait for its URL to respond and set `E2E_BASE_URL` to that URL.

A remote test environment may already have its own server. A local Docker service must be reachable from the runner. Arrange server-side resets between tests that modify data, as explained in [the application guide](../guides/web-application.md#keep-tests-independent).
