# Custom PHPUnit base classes

Use the provided abstract test cases for the simplest integration. If your tests already extend another PHPUnit class, use the corresponding trait instead.

## Existing applications

```php
use Contao\E2eTesting\Application\ApplicationConfig;
use Contao\E2eTesting\Application\ApplicationTestTrait;
use PHPUnit\Framework\TestCase;

final class HomepageTest extends TestCase
{
    use ApplicationTestTrait;

    protected static function createApplicationConfig(): ApplicationConfig
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

## Managed Editions

Use `ManagedEditionTestTrait` and return a `ManagedEditionConfig` from `createApplicationConfig()`. The trait adds `self::managedEdition()` for database, server and Contao operations. See [Databases](databases.md) for the complete trait example and optional Docker service provider.

## Choose Contao's environment

Managed Editions use `prod` by default. Return a configuration with `withAppEnvironment('dev')` when your tests need Contao's development behavior:

```php
$devConfig = $config->withAppEnvironment('dev');
$prodConfig = $config->withAppEnvironment('prod');
```

The environment applies to setup, migrations and HTTP requests. Changing it refreshes the cached application setup.

## Customize application setup and resets

Both configurations implement `ApplicationConfigInterface`, which creates an `ApplicationInterface`. A custom configuration can implement this contract to provide application-state resets without changing the shared trait. `ApplicationInterface::resetState()` defines the reset between tests. URL-based applications close browser contexts, while Managed Editions also restore database fixtures, including when configured directly through `ApplicationTestTrait`.

The first test uses the freshly created application without calling `resetState()`. Your custom factory must therefore prepare the initial test data. Later tests call `resetState()` by default. Override `shouldResetApplication()` only when your test setup intentionally manages resets itself. In that case, manage browser cleanup as well as application state.

## Use the tooling outside PHPUnit

Outside PHPUnit, create an `Application` with its configuration, or call the configuration's `createApplication()`. Always call `release()` in a `finally` block. `BrowserRuntime` owns session tracking, current-page access, traces and cleanup. Your application implementation owns provisioning and other state.
