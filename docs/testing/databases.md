# Databases and test isolation

This page applies to Managed Edition tests. Tests against an existing application use that project's own database setup and reset hooks.

## Use Docker

Start Docker with Linux containers. No manual database creation is needed: the first test starts a reusable `mariadb:11.4` container on a random loopback port and creates an isolated test database.

The last test process stops the container. Later runs restart it, reusing files in `.contao-e2e/database/data`. Parallel workers share container leases, so a worker finishing does not stop another worker's database. After an interrupted run, use `vendor/bin/contao-e2e database:stop` to stop remaining containers.

To choose another image, add this to your Managed Edition configuration:

```php
use Contao\E2eTesting\Database\DockerDatabaseConfig;

$mariaDb = $config->withDatabase(DockerDatabaseConfig::mariaDb('mariadb:10.11'));
$mysql = $config->withDatabase(DockerDatabaseConfig::mysql('mysql:8.0'));
```

Return the chosen configuration from `createApplicationConfig()`. Each image uses a separate container and storage directory. A data provider or CI matrix can test several images.

You can also select an image for one run without changing PHP code:

```shell
CONTAO_E2E_DATABASE_TYPE=mysql CONTAO_E2E_DATABASE_IMAGE=mysql:8.0 vendor/bin/phpunit --testsuite=e2e
```

PowerShell:

```powershell
$env:CONTAO_E2E_DATABASE_TYPE = 'mysql'
$env:CONTAO_E2E_DATABASE_IMAGE = 'mysql:8.0'
vendor/bin/phpunit --testsuite=e2e
```

## Use an existing database server

Set an administrative server URL whose user can create isolated test databases:

```shell
export CONTAO_E2E_DATABASE_URL='mysql://root:password@127.0.0.1:3306'
```

PowerShell:

```powershell
$env:CONTAO_E2E_DATABASE_URL = 'mysql://root:password@127.0.0.1:3306'
```

This overrides Docker provisioning. Use a server URL without a database name, rather than your existing application's `DATABASE_URL`. The tooling creates and resets its own test databases on that server.

## Restore test data

Recipe fixtures are restored between Managed Edition tests by default. Read [fixture references](../recipes/fixtures.md) to define related records and retrieve generated IDs. For explicit resets and read-only fixture reuse, see [caching and maintenance](caching.md#restore-database-fixtures).

## Warm up several database images

Warm-up is optional. The default setup starts each database when a test first needs it. When a suite uses several images, add this to your PHPUnit configuration to start them earlier:

```xml
<extensions>
  <bootstrap class="Contao\E2eTesting\PhpUnit\DockerWarmUpExtension">
    <parameter name="testsuites" value="e2e"/>
  </bootstrap>
</extensions>
```

The comma-separated `testsuites` value selects which suites start Docker services. `AbstractManagedEditionTestCase` advertises the services from your configuration automatically. The extension starts each distinct service once. Tests still wait for it to become ready before using it.

If you use another PHPUnit base class, add the trait and service provider to your test class:

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

    protected static function createApplicationConfig(): ManagedEditionConfig
    {
        $bundleRoot = dirname(__DIR__, 2);
        $composer = ComposerConfig::managedEdition('^5.7')
            ->withPathPackage('acme/example-bundle', $bundleRoot, '1.0.x-dev');

        return ManagedEditionConfig::create(InstallationRecipe::create($composer), $bundleRoot);
    }

    public static function dockerServices(): iterable
    {
        return static::createApplicationConfig()->dockerServices();
    }
}
```

Add your test methods to that class and replace the package name and version with your own. A subclass of `AbstractManagedEditionTestCase` can advertise additional services by yielding from `parent::dockerServices()` before yielding its own services. Custom services implement `DockerServiceInterface`.

See [Windows setup](../running/windows.md) for native Windows prerequisites and [custom PHPUnit integration](phpunit.md) for application configuration.
