# Custom PHPUnit base classes

Use the provided abstract test cases for the simplest integration. If your tests already extend another PHPUnit class, use the corresponding trait instead.

## Existing applications

```php
use Contao\E2eTesting\Application\ApplicationConfig;
use Contao\E2eTesting\Application\ApplicationConfigInterface;
use Contao\E2eTesting\Application\ApplicationTestTrait;
use PHPUnit\Framework\TestCase;

final class HomepageTest extends TestCase
{
    use ApplicationTestTrait;

    protected static function createApplicationConfig(): ApplicationConfigInterface
    {
        return ApplicationConfig::create(getenv('E2E_BASE_URL') ?: 'http://localhost:8080');
    }

    public function testHomepage(): void
    {
        self::application()->createBrowser()->visit('/');
        $this->assertSelectorExists('h1');
    }
}
```

Replace `TestCase` with your own base class. The trait supplies class-level creation and release, inter-test resets, selector assertions and tracing.

For tests that start a local server, return a `LocalApplicationConfig` instead. See [local application servers](webservers.md) for the PHP and custom-command options.

## Managed Editions

Use `ManagedEditionTestTrait` and return a `ManagedEditionConfig` from `createApplicationConfig()`. The trait adds `self::managedEdition()` for database, server and Contao operations. See [Databases](databases.md) for the complete trait example and optional Docker service provider.

## Enable simulated origins in a shared base class

Enable the capability once in the managed configuration used by your shared base class. Individual tests can then choose different public origins for their HTTP requests and BrowserKit clients:

```php
use Contao\E2eTesting\ManagedEdition\AbstractManagedEditionTestCase;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;

abstract class AbstractProjectTestCase extends AbstractManagedEditionTestCase
{
    abstract protected static function createRecipe(): InstallationRecipe;

    protected static function createApplicationConfig(): ManagedEditionConfig
    {
        return ManagedEditionConfig::create(static::createRecipe(), dirname(__DIR__))
            ->withSimulatedOrigins();
    }
}
```

Adjust the project root for your test directory. No proxy fixture is needed. Tests select origins independently with `HttpRequest::withSimulatedOrigin()` or `HttpBrowserOptions::withSimulatedOrigin()`. See [simulated public origins](frontend.md#simulate-a-public-origin) for requests, redirects and the scope of the generated configuration.

## Choose Contao's environment

Managed Editions use `prod` by default. Return a configuration with `withAppEnvironment('dev')` when your tests need Contao's development behavior:

```php
$devConfig = $config->withAppEnvironment('dev');
$prodConfig = $config->withAppEnvironment('prod');
```

The environment applies to setup, migrations and HTTP requests. Changing it refreshes the cached application setup.

## Customize application setup and resets

URL-based, local-server and Managed Edition configurations implement `ApplicationConfigInterface`, which creates an `ApplicationInterface`. A custom configuration can implement this contract to provide application-state resets without changing the shared trait. `ApplicationInterface::resetState()` defines the reset between tests. URL-based and local-server applications close browser contexts, while Managed Editions also restore database fixtures, including when configured directly through `ApplicationTestTrait`.

For SQL applications, call the reusable [database resetter](databases.md#reset-an-existing-applications-database) from your reset implementation and load the initial data afterward. It supports MySQL, MariaDB, SQLite and PostgreSQL independently of Managed Edition provisioning.

The first test uses the freshly created application without calling `resetState()`. Your custom factory must therefore prepare the initial test data. Later tests call `resetState()` by default. Override `shouldResetApplication()` only when your test setup intentionally manages resets itself. In that case, manage browser cleanup as well as application state.

## Use the tooling outside PHPUnit

Outside PHPUnit, call `ApplicationRuntime::shared()->createApplication($config)` to wire its services. Custom application construction requires an explicit `BrowserRuntime` and `ApplicationRuntime`. Always call `release()` in a `finally` block. `BrowserRuntime` owns session tracking, current-page access, traces and context cleanup. `ApplicationRuntime` owns the shared browser processes and closes them on destruction. Your application implementation owns provisioning and other state.
