# Test a web application

Test an application through its URL, whether it is a Contao project or uses another framework or language. The tooling runs real browsers and provides PHPUnit assertions.

Choose how to serve the application: connect to a server already running at a URL, or let the tests start and stop a local server. The tooling can serve a PHP project directly or run your application's own startup command. Your project still prepares dependencies, builds and test data.

For Contao backend tests, add [the Contao-specific checks](contao-project.md) after completing this guide.

## Prepare your application

Use a test environment whose homepage contains an `h1`. Install and build the application using your project's normal commands. For a server that is already running, check that you can open its URL in a browser. The URL-based example uses `http://localhost:8080`, so replace that address with yours.

Install the [supported PHP, Composer and Node.js versions](../reference/requirements.md) on the machine that runs PHPUnit. PHP is needed for the tests even if the application uses another language. Docker and MySQL are needed only if your application uses them.

## Install the tooling

Run from the repository where you want to keep the tests. This can be your application repository or a separate test repository. If it has no `composer.json`, run `composer init` first.

```shell
composer require --dev contao/e2e-testing:dev-main contao/installation-recipe:dev-main
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
use Contao\E2eTesting\Application\ApplicationConfigInterface;

final class HomepageTest extends AbstractApplicationTestCase
{
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

### Start a local server instead

For a PHP project with a `public/` document root, add this import at the top of the test file:

```php
use Contao\E2eTesting\Application\LocalApplicationConfig;
```

Replace the return statement in `createApplicationConfig()` with:

```php
return LocalApplicationConfig::php(dirname(__DIR__, 2));
```

This example assumes the tests are inside the application repository. If you keep tests in a separate repository or serve an application below the monorepo root, pass that application's directory instead.

The tests now start PHP's built-in server on a free loopback port and stop it after the test class. You do not need to start a server manually or set `E2E_BASE_URL`. For another document root or a Node.js, Python or other startup command, see [local application servers](../testing/webservers.md).

## Configure PHPUnit

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

For tests that start their own local server, run from the test repository root:

```shell
vendor/bin/phpunit --testsuite=e2e
```

For tests that connect to an existing server, keep that server running and supply its URL:

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

## Test HTTP endpoints

The same application API supports HTTP requests without starting a browser:

```php
use Contao\E2eTesting\Http\HttpRequest;

$request = HttpRequest::json('POST', '/api/example')
    ->withHeader('Authorization', 'Bearer e2e')
    ->withJson(['title' => 'Example']);

$response = self::application()->send($request);
$this->assertSame(201, $response->getStatusCode());
$this->assertSame('Example', $response->toArray(false)['title']);
```

Paths use the configured application URL, including its subdirectory. For a request without custom headers or a body, use `self::application()->send(HttpRequest::get('/api/example'))`. See [HTTP and JSON requests](../testing/frontend.md#test-http-and-json-endpoints) for JSON bodies and custom media types. Redirects are not followed automatically.

For HTML tests without JavaScript, use `self::application()->createHttpBrowser()`. This returns Symfony BrowserKit's HTTP browser, whose request paths resolve from the server root. For applications in a subdirectory, pass the full URL from `self::application()->uri('/login')`.

### Emulate an origin

With `LocalApplicationConfig::php()` and its generated router, the application can emulate a domain and HTTPS without DNS or certificates:

```php
use Contao\E2eTesting\Http\Origin;

$response = self::application()->send(
    HttpRequest::json('GET', '/api/example', Origin::https('example.test')),
);
```

Pass an optional origin to `HttpRequest::get()` or `HttpRequest::create()` for other HTTP requests. It also works with `createHttpBrowser()` and `createBrowser(origin: ...)`. Existing servers, custom PHP routers and custom startup commands must provide their own domain and HTTPS handling. Without an origin, all methods use the actual application URL.

## Use this in a monorepo

The same setup works in a non-Contao monorepo. Keep the Composer test harness at the repository root for shared tests, or in an application directory for tests owned by that application. Run the installation and PHPUnit commands from that harness directory.

Your application's build or package manager handles local package dependencies. Point `ApplicationConfig` at an existing server URL, or give `LocalApplicationConfig` the application directory and its startup command. You do not need `MonorepoProject` for these tests.

If the monorepo contains several applications, give each application's test class its own configuration. Local servers get independent ports. For existing servers, read a separate URL variable such as `SHOP_E2E_BASE_URL` or `ADMIN_E2E_BASE_URL` in each class.

## Keep tests independent

The tooling closes browser contexts between tests, clearing their cookies and browser storage. It does not reset your application's database, uploads or other server-side state. Use your project's test setup to restore those before tests that modify them.

See [custom PHPUnit integration](../testing/phpunit.md) for reset hooks. Continue with [browser assertions](../testing/browsers.md), [debugging traces](../running/traces.md) or [CI setup](../running/ci.md).
