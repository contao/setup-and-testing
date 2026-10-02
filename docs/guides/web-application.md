# Test an existing web application

Test an application through its URL, whether it is a Contao project or uses another framework or language. The tooling runs real browsers and provides PHPUnit assertions.

Your application must be reachable before you run the tests. It can use your normal development server, a Docker service or a remote test environment. You only start a server if one is not already running. Supplying a URL does not launch or configure the application.

For Contao backend tests, add [the Contao-specific checks](contao-project.md) after completing this guide.

## Prepare your application

Use a test environment whose homepage contains an `h1`. Check that you can open it in a browser. The examples use `http://localhost:8080`, so replace that address with your application's URL.

Install the [supported PHP, Composer and Node.js versions](../reference/requirements.md) on the machine that runs PHPUnit. PHP is needed for the tests even if the application uses another language. Docker and MySQL are needed only if your application uses them.

## Install the tooling

Run from the repository where you want to keep the tests. This can be your application repository or a separate test repository. If it has no `composer.json`, run `composer init` first.

```shell
composer require --dev contao/e2e-testing
vendor/bin/playwright-install --browsers
```

Add `.contao-e2e/` to that repository's `.gitignore`. It contains test caches and traces.

## Add your first test

Create `tests/E2e/HomepageTest.php`:

```php
<?php

declare(strict_types=1);

use Contao\E2eTesting\Application\AbstractApplicationTestCase;
use Contao\E2eTesting\Application\ApplicationConfig;

final class HomepageTest extends AbstractApplicationTestCase
{
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

Create `phpunit.xml.dist` in the test repository root, or add this suite to your existing configuration:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" cacheDirectory=".contao-e2e/cache/phpunit">
  <testsuites>
    <testsuite name="e2e">
      <directory>tests/E2e</directory>
    </testsuite>
  </testsuites>
</phpunit>
```

## Run the test

With your application reachable, run from the test repository root:

```shell
E2E_BASE_URL=http://localhost:8080 vendor/bin/phpunit --testsuite=e2e
```

PowerShell:

```powershell
$env:E2E_BASE_URL = 'http://localhost:8080'
vendor/bin/phpunit --testsuite=e2e
```

PHPUnit should report a passing test. Replace the `h1` check with an assertion that matters for your application.

A URL subdirectory is supported. For example, with `E2E_BASE_URL=https://example.test/app`, `visit('/login')` opens `/app/login`. Use an absolute HTTP or HTTPS URL without a query or fragment.

## Use this in a monorepo

The same setup works in a non-Contao monorepo. Keep the Composer test harness at the repository root for shared tests, or in an application directory for tests owned by that application. Run the installation and PHPUnit commands from that harness directory.

Build and serve the application using your monorepo's normal commands, then point `ApplicationConfig` at its URL. Your application's build or package manager handles local package dependencies. You do not need `MonorepoProject` for URL-based tests.

If the monorepo serves several applications, give each application's test class its own base URL in `createApplicationConfig()`. For example, read `SHOP_E2E_BASE_URL` in shop tests and `ADMIN_E2E_BASE_URL` in admin tests.

## Keep tests independent

The tooling closes browser contexts between tests, clearing their cookies and browser storage. It does not reset your application's database, uploads or other server-side state. Use your project's test setup to restore those before tests that modify them.

See [custom PHPUnit integration](../testing/phpunit.md) for reset hooks. Continue with [browser assertions](../testing/browsers.md), [debugging traces](../running/traces.md) or [CI setup](../running/ci.md).
