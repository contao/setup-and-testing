# Test an existing Contao project

Use this guide to test the frontend and backend of a Contao project you already run. It uses the same application testing setup as [any other web application](web-application.md), with helpers for Contao's backend.

## Set up the tests

Follow [Test a web application](web-application.md) to install the tooling, configure PHPUnit and run your first frontend test. Use your Contao project's root as the test repository and its test installation URL as `E2E_BASE_URL`.

You can use the project's existing server or let the tests [start a local PHP server](../testing/webservers.md). Neither option installs Contao, runs migrations or resets your project's database. If you want installation and database setup handled automatically, use a [Managed Edition test](contao-extension.md).

## Add a backend test

Confirm that `/contao/login` is available at your test URL. Create `tests/E2e/BackendLoginTest.php` in the project root:

```php
<?php

declare(strict_types=1);

use Contao\E2eTesting\Application\AbstractApplicationTestCase;
use Contao\E2eTesting\Application\ApplicationConfig;
use Contao\E2eTesting\Application\ApplicationConfigInterface;
use Contao\E2eTesting\Browser\BackendBrowser;

final class BackendLoginTest extends AbstractApplicationTestCase
{
    protected static function createApplicationConfig(): ApplicationConfigInterface
    {
        return ApplicationConfig::create(getenv('E2E_BASE_URL') ?: 'http://localhost:8080');
    }

    public function testBackendLoginPage(): void
    {
        $backend = new BackendBrowser(self::application()->createBrowser());
        $backend->visit('/contao/login');

        $this->assertSelectorExists('input[name="username"]');
    }
}
```

If the frontend tests use `LocalApplicationConfig`, return the same local configuration here and run `vendor/bin/phpunit --testsuite=e2e` without a URL variable. Each test class starts and stops its own server.

For the URL-based configuration above, run from the project root with the server available:

```shell
E2E_BASE_URL=http://localhost:8080 vendor/bin/phpunit --testsuite=e2e
```

PowerShell:

```powershell
$env:E2E_BASE_URL = 'http://localhost:8080'
vendor/bin/phpunit --testsuite=e2e
```

PHPUnit should report passing frontend and backend tests. This backend check needs no user account.

## Test authenticated actions

Create a backend test user through your project's fixtures or setup. Inside a test, log in before using the backend helpers:

```php
$backend = new BackendBrowser(self::application()->createBrowser());
$backend->visit('/contao/login');
$backend->submitLogin('admin', 'password');
```

Use your test user's credentials. Continue with [backend interactions](../testing/backend.md) for forms, records, file pickers and dynamic palettes. Tests that change data need your project's own reset hooks, as explained in [the shared application guide](web-application.md#keep-tests-independent).
