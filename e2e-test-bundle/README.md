# Contao E2E test bundle

`contao/e2e-test-bundle` prepares a real Contao Managed Edition, migrates an isolated MySQL/MariaDB database, loads installation recipes, and exposes raw HTTP and Panther clients. It does not require a Contao bundle itself, so the test suite selects the Contao version in its recipe.

If Docker is available, no database setup is needed. The first test starts a reusable `mariadb:11.4` container on a random loopback port; subsequent runs reuse it. Its `/var/lib/mysql` directory is bind-mounted to `.contao-e2e/database/data`, so all generated database files remain inside the project-local E2E workspace.

Select a database explicitly in the PHPUnit configuration when an extension supports a particular database range:

```php
use Contao\E2eTestBundle\Database\DockerDatabaseConfig;

$mariaDb = $config->withDatabase(DockerDatabaseConfig::mariaDb('mariadb:10.11'));
$mysql = $config->withDatabase(DockerDatabaseConfig::mysql('mysql:8.0'));
```

Different types and image versions use independent reusable containers and storage directories. This makes those configurations suitable for a PHPUnit data provider or separate CI jobs. A CI matrix can configure the same tests without changing PHP code:

```shell
CONTAO_E2E_DATABASE_TYPE=mysql CONTAO_E2E_DATABASE_IMAGE=mysql:8.0 composer e2e-tests
CONTAO_E2E_DATABASE_TYPE=mariadb CONTAO_E2E_DATABASE_IMAGE=mariadb:10.11 composer e2e-tests
```

An administrative database URL that may create test databases overrides Docker:

```shell
export CONTAO_E2E_DATABASE_URL='mysql://root:password@127.0.0.1:3306'
```

Use the trait with PHPUnit 10 through 13; no test base class is imposed:

```php
use Contao\E2eTestBundle\Browser\BrowserOptions;
use Contao\E2eTestBundle\ManagedEdition\ManagedEditionConfig;
use Contao\E2eTestBundle\ManagedEdition\ManagedEditionTestTrait;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;
use PHPUnit\Framework\TestCase;

final class LoginTest extends TestCase
{
    use ManagedEditionTestTrait;

    protected static function createManagedEditionConfig(): ManagedEditionConfig
    {
        $composer = ComposerConfig::managedEdition('^5.7')
            ->withPathPackage('acme/example-bundle', dirname(__DIR__), '1.0.x-dev');

        return ManagedEditionConfig::create(
            InstallationRecipe::create($composer)->withFixtureFile(__DIR__.'/fixtures.yaml'),
            dirname(__DIR__),
        );
    }

    public function testLoginPage(): void
    {
        $backend = self::managedEdition()->createFirefoxBackendBrowser();
        $client = $backend->client();
        $client->request('GET', '/contao/login');
        $backend->submitLogin('admin', 'password');

        $this->assertSelectorTextContains('body', 'Contao');
    }
}
```

`BackendBrowser` wraps recurring Contao backend interactions without imposing another PHPUnit trait or base class. Chrome and Firefox variants are available from `ManagedEdition`; the underlying Panther client remains accessible through `client()` for arbitrary browser operations and assertions.

```php
$backend = self::managedEdition()->createFirefoxBackendBrowser();
$backend->client()->request('GET', '/contao/login');
$backend->submitLogin('admin', 'password');
$backend->clickLink('Articles');
$backend->submitNew();
$backend->submitAction('Paste at the top');
$backend->select('type', 'text');
$backend->waitFor('textarea[name="text"]');
$backend->fillRichText('text', 'Content created by an E2E test.');
$backend->check('published');
$backend->submitForm('Save and close', ['headline[value]' => 'Headline']);
```

The wrapper also supports buttons and operation links whose title starts with a translated label. `selectFile($field, $path, $expectedValue)` opens Contao's real modal file picker, expands nested directories, applies the selection, and optionally waits until the hidden widget value matches a known UUID.

Use the browser-independent options object when a real browser request must exercise locale negotiation. It maps the accepted languages to the appropriate Firefox or Chrome preference:

```php
$options = BrowserOptions::create()->withAcceptLanguage('de-CH,de,en');
$backend = self::managedEdition()->createFirefoxBackendBrowser(options: $options);
// The same options work with createChromeBackendBrowser().
```

Firefox and Chrome drivers are provisioned automatically with BDI when they are not already available on `PATH`. Matching drivers are cached in `.contao-e2e/drivers`, so subsequent test runs do not download them again. Set `GITHUB_TOKEN` in CI if unauthenticated GitHub API rate limits affect geckodriver detection.

Recipe file mappings copy files into the Managed Edition. Call `ManagedEdition::synchronizeFiles('files/path/example.jpg')` when a test also needs those files registered in Contao's DBAFS, for example before selecting them in a backend file-tree widget. With no path, the complete configured filesystem is synchronized.

Without an origin, Panther uses the local E2E server URI directly so that absolute redirects and cookies stay on the same browser origin. Pass `Origin::http('example.test')` or `Origin::https('example.test')` when a test must emulate a page DNS entry or HTTPS; the server maps that origin without requiring a real domain or certificate.

Each consumer project gets one ignored `.contao-e2e/` workspace. Dependency, application, and fixture fingerprints are separate: unchanged Composer input reuses `vendor/`; source or configuration changes rerun setup and migrations; fixture-only changes only reset and reload the database. Parallel processes acquire separate installation and database slots.

Xdebug is disabled for Composer, setup, migration, and other managed subprocesses as well as for the E2E web server. The `BackendBrowser` submit helpers wait for the submitted form to be replaced, which works with Contao's Turbo navigation without Panther's full-document navigation delay.

`ManagedEdition::resetDatabase()` returns a `FixtureResult`. Call `$result->value('page_home')` to obtain the generated primary key of a named fixture, or pass a second column name to read another resolved value. `$result->interpolate('/pages/{page_home}')` substitutes generated values in paths or other strings.

For monorepos, `MonorepoProject` discovers an explicit root package version or the `dev-main` branch alias and falls back
to `dev-main` when neither exists. It also reads the package names from local `composer.json` files:

```php
use Contao\E2eTestBundle\Composer\MonorepoProject;

$monorepo = MonorepoProject::discover(dirname(__DIR__));
$composer = $monorepo->configureComposer(
    ComposerConfig::managedEdition('^5.7'),
    'packages/example-bundle',
);
```

For HTTP tests without JavaScript, use Symfony's BrowserKit client. It returns a DomCrawler instance and supports links,
forms, cookies, history, and access to the last response:

```php
$browser = self::managedEdition()->createHttpBrowser(Origin::https('example.test'));
$crawler = $browser->request('GET', '/');

$this->assertSame(200, $browser->getInternalResponse()->getStatusCode());
$this->assertSame('Example', trim($crawler->filterXPath('//head/title')->text()));
```

Full Managed Editions are stored below `.contao-e2e/cache/installations/<fingerprint>/<slot>/project`. The matching
MySQL or MariaDB database runs in the configured server or a reusable Docker container. The default database files are stored below `.contao-e2e/database/data`; additional image variants use `.contao-e2e/database/<fingerprint>/data`. The `runtime/` directory only contains
the lightweight webserver router and origin mapping.

`CONTAO_E2E_DIRECTORY` overrides the workspace, and `CONTAO_E2E_NO_CACHE=1` forces a fresh dependency installation. The `contao-e2e` executable is a Symfony Console application; run `vendor/bin/contao-e2e list` for all commands. `cache:clear` safely clears reusable installations, while `database:stop` stops every database variant belonging to the current project.
