# Contao E2E testing

`contao/e2e-testing` owns the test runtime. It consumes recipes from `contao/installation-recipe` to prepare a real Contao Managed Edition and migrate an isolated MySQL/MariaDB database. Tests can make direct HTTP requests, use Symfony BrowserKit for HTTP tests without JavaScript, or drive a real browser with Playwright. The test suite selects the Contao version in its recipe because this library does not require a Contao bundle.

Install it as a development dependency in the project under test. Composer also installs `contao/installation-recipe`, which provides the recipe model used below:

```shell
composer require --dev contao/e2e-testing
```

## Database setup

If Docker is available, no database setup is needed. The first test starts a reusable `mariadb:11.4` container on a random loopback port. The last E2E process stops it, and subsequent runs restart the same container. Its `/var/lib/mysql` directory is bind-mounted to `.contao-e2e/database/data`, so all generated database files remain inside the project-local E2E workspace. Parallel test workers keep shared leases and only the final worker stops the database. If a process is killed before PHP can run its shutdown handlers, `database:stop` cleans up any remaining containers.

Select a database explicitly in the PHPUnit configuration when an extension supports a particular database range:

```php
use Contao\E2eTesting\Database\DockerDatabaseConfig;

$mariaDb = $config->withDatabase(DockerDatabaseConfig::mariaDb('mariadb:10.11'));
$mysql = $config->withDatabase(DockerDatabaseConfig::mysql('mysql:8.0'));
```

Different types and image versions use independent reusable containers and storage directories. This makes those configurations suitable for a PHPUnit data provider or separate CI jobs.

Database warm-up is optional. Without the extension, each test starts its database variant on demand and waits until it is ready. When one PHPUnit run uses several database images, enable the PHPUnit extension in the E2E configuration to start them earlier:

```xml
<extensions>
  <bootstrap class="Contao\E2eTesting\PhpUnit\DockerWarmUpExtension">
    <parameter name="testsuites" value="e2e"/>
  </bootstrap>
</extensions>
```

The comma-separated `testsuites` parameter selects the PHPUnit suites whose Docker services should be warmed. `ManagedEditionConfig::dockerServices()` exposes the services implied by the configuration, and `AbstractManagedEditionTestCase` implements the provider by returning that collection. The complete bundle example below uses this base class.

If a test already has another base class, it can continue using `ManagedEditionTestTrait` and advertise services directly by implementing `DockerServiceProviderInterface`:

```php
use Contao\E2eTesting\Docker\DockerServiceProviderInterface;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\E2eTesting\ManagedEdition\ManagedEditionTestTrait;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;
use PHPUnit\Framework\TestCase;

final class ManagedEditionSmokeTest extends TestCase implements DockerServiceProviderInterface
{
    use ManagedEditionTestTrait;

    protected static function createManagedEditionConfig(): ManagedEditionConfig
    {
        $bundleRoot = dirname(__DIR__, 2);
        $composer = ComposerConfig::managedEdition('^5.7')
            ->withPathPackage('acme/example-bundle', $bundleRoot, '1.0.x-dev');

        return ManagedEditionConfig::create(InstallationRecipe::create($composer), $bundleRoot);
    }

    public static function dockerServices(): iterable
    {
        return static::createManagedEditionConfig()->dockerServices();
    }
}
```

When a selected suite starts, the extension collects and deduplicates its services before warming them without waiting for readiness. Tests still wait for a service when they first use it. A subclass can advertise additional services by overriding `dockerServices()`, yielding from `parent::dockerServices()`, and then yielding its own services. Other suites, such as `unit`, do not start Docker. The provider mechanism is independent of `ManagedEditionTestTrait`, and future service types such as Redis can implement `DockerServiceInterface` without changing the PHPUnit extension.

A CI matrix can configure the same tests without changing PHP code:

```shell
CONTAO_E2E_DATABASE_TYPE=mysql CONTAO_E2E_DATABASE_IMAGE=mysql:8.0 composer e2e-tests
CONTAO_E2E_DATABASE_TYPE=mariadb CONTAO_E2E_DATABASE_IMAGE=mariadb:10.11 composer e2e-tests
```

An administrative database URL that may create test databases overrides Docker:

```shell
export CONTAO_E2E_DATABASE_URL='mysql://root:password@127.0.0.1:3306'
```

The PowerShell equivalent on Windows is:

```powershell
$env:CONTAO_E2E_DATABASE_URL = 'mysql://root:password@127.0.0.1:3306'
```

Windows is supported with native PHP, Composer, and Node.js 20 or newer. The automatic database requires Docker Desktop configured for Linux containers. Alternatively, configure an existing MySQL or MariaDB server with `CONTAO_E2E_DATABASE_URL`. Composer creates Windows command proxies for `contao-e2e`, PHPUnit, and Playwright, while the library invokes PHP, Composer, Git, and Docker without relying on a POSIX shell.

The managed edition runs in `prod` by default. Set the environment on the test configuration when a test needs Contao's development behavior:

```php
$devConfig = $config->withAppEnvironment('dev');
$prodConfig = $config->withAppEnvironment('prod');
```

Changing the environment refreshes the cached application setup. The selected environment applies to Contao setup commands, database migration, and HTTP requests. With `ManagedEditionTestTrait`, return the desired configuration from `createManagedEditionConfig()` for each test class.

## Browser tests

Install the Playwright browser binaries once after requiring the package:

```shell
vendor/bin/playwright-install --browsers
```

Use `vendor/bin/playwright-install --with-deps` on a fresh Linux CI runner to install the required system libraries as well. Playwright caches matching Chromium, Firefox, and WebKit binaries outside the project and reuses them between runs.

## CI caches

`cache:metadata` writes separate portable keys for Playwright browser binaries and reusable E2E setup data, then prints each opaque fingerprint and cache root as JSON:

```shell
vendor/bin/contao-e2e cache:metadata
```

The keys are written to `.contao-e2e/cache-keys/playwright` and `.contao-e2e/cache-keys/e2e`. Any CI system can use their contents directly or hash the files. They remain separate because browser binaries and the rest of the E2E setup have different invalidation rules.

The Playwright fingerprint uses the concrete version from the installed Node package and the browser revisions from Playwright's installed browser registry. The Managed Edition fingerprint covers the cache format, PHP major and minor version, operating system, architecture, the installed `contao/e2e-testing` and `contao/installation-recipe` versions, and Composer settings that can affect dependency resolution.

The Playwright PHP package resolves the semver constraint in its bundled `package.json` through npm, pnpm, or Yarn. Its resolved Node package and browser registry must therefore exist before metadata can be calculated. Prepare those dependencies explicitly after Composer installation:

```shell
vendor/bin/playwright-install
vendor/bin/contao-e2e cache:metadata
```

The first command may access the network to install Node packages. It does not install browser binaries without `--browsers`. `cache:metadata` never performs this preparation or accesses the network itself.

A complete GitHub Actions job can keep every cache payload under `.contao-e2e/cache` and restore both groups independently:

```yaml
jobs:
    e2e:
        runs-on: ubuntu-latest
        env:
            PLAYWRIGHT_BROWSERS_PATH: .contao-e2e/cache/playwright
        steps:
            - uses: actions/checkout@v6

            - uses: shivammathur/setup-php@v2
              with:
                  php-version: '8.4'
                  extensions: intl, mbstring, pdo_mysql, zip
                  coverage: none

            - name: Install Composer dependencies
              run: composer install --no-interaction --no-progress

            - name: Prepare Playwright Node dependencies
              run: vendor/bin/playwright-install

            - name: Calculate E2E cache metadata
              run: vendor/bin/contao-e2e cache:metadata

            - name: Restore Playwright browsers
              uses: actions/cache@v4
              with:
                  path: .contao-e2e/cache/playwright
                  key: playwright-${{ hashFiles('.contao-e2e/cache-keys/playwright') }}

            - name: Restore E2E setup cache
              uses: actions/cache@v4
              with:
                  path: .contao-e2e/cache/e2e
                  key: contao-e2e-${{ hashFiles('.contao-e2e/cache-keys/e2e') }}

            - name: Install and verify Playwright browsers
              run: vendor/bin/playwright-install --browsers

            - name: Run PHPUnit
              run: vendor/bin/phpunit --configuration=phpunit.xml.dist
```

The cache root contains separate `playwright` and `e2e` groups. The package owns the contents of each group, so adding another reusable E2E setup cache does not require consuming projects to update their CI configuration. Database data, process locks, runtime files, and failure artifacts are deliberately excluded. The existing per-installation dependency and application fingerprints still validate restored installations, so project source files do not need to be part of the outer CI cache key.

GitHub Actions restricts cache access by branch and ref. A pull request can restore caches created on its base branch, while caches created for a pull request's merge ref are only available to reruns of that pull request. Run this job on pushes to the default branch as well as pull requests so the default branch regularly creates a cache that different pull requests can reuse.

### Test a bundle from its working tree

The following example lives in a Contao bundle repository, not in this library. Install `contao/e2e-testing` as a development dependency as shown above, then put this test in `tests/E2e/ManagedEditionSmokeTest.php`. Replace `acme/example-bundle` with the `name` from your bundle's `composer.json` and choose a version that satisfies its Composer constraints. The version does not have to match the name of your current Git branch.

```php
<?php

declare(strict_types=1);

use Contao\E2eTesting\ManagedEdition\AbstractManagedEditionTestCase;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;

final class ManagedEditionSmokeTest extends AbstractManagedEditionTestCase
{
    protected static function createManagedEditionConfig(): ManagedEditionConfig
    {
        $bundleRoot = dirname(__DIR__, 2);
        $composer = ComposerConfig::managedEdition('^5.7')
            ->withPathPackage('acme/example-bundle', $bundleRoot, '1.0.x-dev');

        return ManagedEditionConfig::create(InstallationRecipe::create($composer), $bundleRoot);
    }

    public function testBackendLoginPage(): void
    {
        $backend = self::managedEdition()->createBackendBrowser();
        $backend->visit('/contao/login');

        $this->assertSelectorExists('input[name="username"]');
    }
}
```

Add `tests/E2e` to your existing PHPUnit test suite, or use this minimal `phpunit.xml.dist` in the bundle root:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" cacheDirectory=".contao-e2e/cache/phpunit">
  <testsuites>
    <testsuite name="e2e">
      <directory>tests/E2e</directory>
    </testsuite>
  </testsuites>
  <extensions>
    <bootstrap class="Contao\E2eTesting\PhpUnit\DockerWarmUpExtension">
      <parameter name="testsuites" value="e2e"/>
    </bootstrap>
  </extensions>
</phpunit>
```

From the bundle root, run:

```shell
vendor/bin/phpunit --configuration=phpunit.xml.dist tests/E2e/ManagedEditionSmokeTest.php
```

`withPathPackage()` makes Composer require your bundle from its local directory and symlink it into the Managed Edition's `vendor/`. The test sees the current working tree, including uncommitted PHP changes. Source changes invalidate the cached application setup on the next test process. Changes to a linked bundle's `composer.json` select a fresh dependency installation so Composer resolves the new requirements.

The trait works with PHPUnit 10 through 13 and does not impose a test base class. Once the smoke test runs, replace its login-page assertion with checks for your bundle's behavior. Add database fixtures with `InstallationRecipe::withFixtureFile()` when the test needs existing pages or backend users.

### Test-specific DCA

Use `withDcaFile()` to add a PHP DCA file from the test suite to the Managed Edition. The file is copied to the project's `contao/dca/` directory before Contao setup and database migration. Its basename determines the DCA file name, so a source named `tl_content.php` configures `tl_content`:

```php
$config = ManagedEditionConfig::create($recipe, dirname(__DIR__))
    ->withDcaFile(__DIR__.'/dca/tl_content.php');
```

The DCA file can define a field for a widget supplied by the package under test, including `eval` options that no core field uses:

```php
<?php

use Contao\CoreBundle\DataContainer\PaletteManipulator;

$GLOBALS['TL_DCA']['tl_content']['fields']['widget_test'] = [
    'inputType' => 'myWidget',
    'eval' => ['myOption' => 'variant-a'],
    'sql' => ['type' => 'string', 'default' => ''],
];

PaletteManipulator::create()
    ->addField('widget_test', 'text')
    ->applyToPalette('text', 'tl_content');
```

Use another field or a different DCA file for another `eval` variant. DCA file changes invalidate the cached application setup, so the next test run installs the updated definition. The method returns a new configuration and leaves the original recipe unchanged.

### Backend interactions

`BackendBrowser` wraps recurring Contao backend interactions without imposing another PHPUnit trait or base class. Firefox is the default, while Chromium and WebKit are selected with `BrowserType`. The underlying Playwright page, context, and browser session remain accessible for arbitrary operations and assertions.

```php
$backend = self::managedEdition()->createBackendBrowser();
$backend->visit('/contao/login');
$backend->submitLogin('admin', 'password');
$backend->clickLink('Articles');
$backend->submitNew();
$backend->submitAction('Paste at the top');
$backend->selectAndWaitForAjax('type', 'text');
$backend->waitFor('textarea[name="text"]');
$backend->fillRichText('text', 'Content created by an E2E test.');
$backend->check('published');
$backend->submitForm('Save and close', ['headline[value]' => 'Headline']);
```

Dynamic Contao palettes finish asynchronously. Use `checkAndWaitForAjax()` or `selectAndWaitForAjax()` when changing a field causes Contao to rebuild part of the form. For extension-specific controls, `waitForAjax()` accepts the Playwright action that triggers the update:

```php
$backend->waitForAjax(
    static fn () => $backend->page()->locator('[data-action="load-widget"]')->click(),
);
$backend->waitFor('#extension_widget');
```

Playwright's native navigation handling follows the browser's document lifecycle. Many Contao backend links are
intercepted by Turbo, which replaces the rendered page without creating a new document. The Playwright click therefore
finishes once the element has been clicked, while the Turbo render may still be in progress. Native navigation waiting
cannot reliably close that gap because a Turbo visit is not a browser navigation.

`waitForNavigation()` registers a `turbo:render` listener before executing the action, avoiding a race with fast Turbo
responses. It completes when that event fires or when a full document navigation replaces the current page. Backend
helpers that trigger navigation use it automatically. Wrap extension-specific actions in it whenever they may result
in either kind of navigation:

```php
$backend->waitForNavigation(
    static fn () => $backend->page()->getByRole('link', ['name' => 'Extension settings'])->click(),
);
```

Use `waitForTurboNavigation()` when the action must render through Turbo, or `waitForFullNavigation()` when it must load a new document. Both register a navigation marker before running the action:

```php
$backend->waitForTurboNavigation(
    static fn () => $backend->page()->getByRole('link', ['name' => 'Articles'])->click(),
);
$backend->waitForFullNavigation(
    static fn () => $backend->page()->getByRole('link', ['name' => 'Log out'])->click(),
);
```

Regular Playwright locator auto-waiting remains sufficient for actions that only update the current page without
navigating. Use `waitForAjax()` instead when a Contao AJAX callback rebuilds part of a form.

The wrapper also supports buttons and operation links whose title starts with a translated label. `selectFile($field, $path, $expectedValue)` opens Contao's real modal file picker, expands nested directories, applies the selection, and optionally waits until the hidden widget value matches a known UUID.

### Traces

Traces are meant for debugging failing tests locally and is disabled by default.
Set `CONTAO_E2E_TRACE` to record a [Playwright trace](https://playwright.dev/docs/trace-viewer) for every browser
session. A trace contains a DOM snapshot and screenshot for every action as well as network requests, console messages
and sources, so a failure can be inspected step by step after the run:

| Value        | Behavior                                  |
|--------------|-------------------------------------------|
| `on-failure` | Record every test, keep failed tests only |
| `always`     | Record and keep every test                |

Set the variable in your shell for a single run, or add it to a local `phpunit.xml` with
`<env name="CONTAO_E2E_TRACE" value="on-failure"/>` to keep it enabled for every local run:

```shell
CONTAO_E2E_TRACE=on-failure vendor/bin/phpunit --testsuite=e2e
```

```shell
CONTAO_E2E_TRACE=always vendor/bin/phpunit --testsuite=e2e
```

```shell
npx playwright show-trace .contao-e2e/traces/<TestClass>-<test>.zip
```

The path of each written trace is printed in the CLI. The Playwright CLI is installed together with the browsers by
`vendor/bin/playwright-install`. You can alternatively drop the file on https://trace.playwright.dev, which will open
it locally in the browser.

Recording slows down every test, so leave `CONTAO_E2E_TRACE` unset on CI and only enable it for a local run.

### Playwright options

The Playwright configuration can be adjusted using environment variables, so you can change its options without
modifying the tests. The most useful options are:

| Variable        | Default | Behavior                                                     |
|-----------------|---------|--------------------------------------------------------------|
| `PW_TIMEOUT_MS` | `30000` | Timeout for browser actions, waits and navigations           |
| `PW_HEADLESS`   | `true`  | Set to `false` to watch the browser while the tests run      |
| `PW_SLOWMO_MS`  | `0`     | Slows down every browser operation by the given amount       |
| `PW_CHANNEL`    |         | Uses an installed browser channel, e.g. `chrome` or `msedge` |

For example, lower the timeout to let a broken test fail fast while writing it, or raise it on a slow machine:

```shell
PW_TIMEOUT_MS=1000 vendor/bin/phpunit --testsuite=e2e
```

Set the variables in your shell for a single run, or add them to a local `phpunit.xml`, e.g.
`<env name="PW_TIMEOUT_MS" value="1000"/>`, to keep them for every local run. The connection to the Playwright server
never uses less than 30 seconds, so launching the browser still works with a low timeout.

## Isolation and caching

Use the browser-independent options object when a real browser request must exercise locale negotiation. It maps the accepted languages to an `Accept-Language` header for every browser engine:

```php
$options = BrowserOptions::create()->withAcceptLanguage('de-CH,de,en');
$backend = self::managedEdition()->createBackendBrowser(options: $options);
// The header works with Chromium, Firefox, and WebKit.
```

The Playwright process and launched browser engine are reused for the test class. Every call to `createBrowser()` or `createBackendBrowser()` creates a cheap, isolated browser context with independent cookies and storage, so tests can represent multiple simultaneous users. Active contexts are closed before the database is reset, while the browser process remains available for the next test.

Recipe file mappings copy files into the Managed Edition. Call `ManagedEdition::synchronizeFiles('files/path/example.jpg')` when a test also needs those files registered in Contao's DBAFS, for example before selecting them in a backend file-tree widget. With no path, the complete configured filesystem is synchronized.

Without an origin, Playwright uses the local E2E server URI directly so that absolute redirects and cookies stay on the same browser origin. Pass `Origin::http('example.test')` or `Origin::https('example.test')` when a test must emulate a page DNS entry or HTTPS. The server maps that origin without requiring a real domain or certificate.

Each consumer project gets one ignored `.contao-e2e/` workspace. Dependency, application, and fixture fingerprints are separate: unchanged Composer input reuses `vendor/`; source or configuration changes rerun setup and migrations; fixture-only changes only reset and reload the database. Parallel processes acquire separate installation and database slots.

Path-package source fingerprints are cached for the lifetime of the PHPUnit process. Test code should not modify package source files while the suite is running; call `ProcessCachedSourceFingerprint::reset()` if a specialized test intentionally does so.

Read-only tests with a data provider can avoid repeatedly loading an unchanged fixture set. `prepareDatabase($fixtures)` fingerprints the fixture contents and only resets the database when they change; repeated calls still clear active browser sessions and mutable runtime caches. Use `resetDatabase()` instead whenever a test may have modified database state.

Keep PHPUnit's disposable cache in the E2E workspace by configuring it in `phpunit.xml.dist`:

```xml
<phpunit cacheDirectory=".contao-e2e/cache/phpunit">
```

Alternatively, pass `--cache-directory=.contao-e2e/cache/phpunit` when running the E2E test suite. This avoids creating a separate `.phpunit.cache/` directory.

Xdebug is disabled for Composer, setup, migration, and other managed subprocesses as well as for the E2E web server. Playwright locators automatically wait for actionable elements and work with Contao's Turbo navigation without manual sleeps.

`ManagedEdition::resetDatabase()` returns a `FixtureResult`. Call `$result->value('page_home')` to obtain the generated primary key of a named fixture, or pass a second column name to read another resolved value. `$result->interpolate('/pages/{page_home}')` substitutes generated values in paths or other strings.

## Monorepo projects

For monorepos, `MonorepoProject` discovers an explicit root package version or the `dev-main` branch alias and falls back
to `dev-main` when neither exists. It also reads the package names from local `composer.json` files:

```php
use Contao\E2eTesting\Composer\MonorepoProject;

$monorepo = MonorepoProject::discover(dirname(__DIR__));
$composer = $monorepo->configureComposer(
    ComposerConfig::managedEdition('^5.7'),
    'packages/example-bundle',
);
```

## HTTP tests and maintenance

For HTTP tests without JavaScript, use Symfony's BrowserKit client. It returns a DomCrawler instance and supports links,
forms, cookies, history, and access to the last response:

```php
$browser = self::managedEdition()->createHttpBrowser(Origin::https('example.test'));
$crawler = $browser->request('GET', '/');

$this->assertSame(200, $browser->getInternalResponse()->getStatusCode());
$this->assertSame('Example', trim($crawler->filterXPath('//head/title')->text()));
```

Full Managed Editions are stored below `.contao-e2e/cache/e2e/installations/<fingerprint>/<slot>/project`. The matching
MySQL or MariaDB database runs in the configured server or a reusable Docker container. The default database files are stored below `.contao-e2e/database/data`; additional image variants use `.contao-e2e/database/<fingerprint>/data`. The `runtime/` directory only contains
the lightweight webserver router and origin mapping.

`CONTAO_E2E_DIRECTORY` overrides the workspace, and `CONTAO_E2E_NO_CACHE=1` forces a fresh dependency installation. The `contao-e2e` executable is a Symfony Console application; run `vendor/bin/contao-e2e list` for all commands. `cache:clear` safely clears reusable installations, while `database:stop` stops every database variant belonging to the current project. It refuses to interrupt active tests unless `--force` is passed.
