# Run tests on Windows

Use native PHP, Composer and Node.js 20 or newer. Install the PHP extensions required by your application. Managed Edition tests need Docker Desktop configured for Linux containers, or an administrative MySQL/MariaDB connection. Existing-application tests only need the infrastructure their own application uses.

Run commands from the consumer project root. Composer generates Windows proxies for PHPUnit, Playwright and `contao-e2e`:

```powershell
composer require --dev contao/e2e-testing
vendor/bin/playwright-install --browsers
vendor/bin/phpunit --testsuite=e2e
```

The library invokes PHP, Composer, Git and Docker without relying on a POSIX shell. Make those commands available on `PATH`.

## Environment variables

POSIX inline assignments such as `E2E_BASE_URL=... vendor/bin/phpunit` do not work in PowerShell. Set each variable before the command:

```powershell
$env:E2E_BASE_URL = 'http://localhost:8080'
$env:CONTAO_E2E_DATABASE_URL = 'mysql://root:password@127.0.0.1:3306'
$env:CONTAO_E2E_TRACE = 'on-failure'
vendor/bin/phpunit --testsuite=e2e
```

`E2E_BASE_URL` is read by the existing-application examples. `CONTAO_E2E_DATABASE_URL` applies to Managed Edition provisioning. Set only the variables your test mode uses. Remove a variable when you no longer need the override:

```powershell
Remove-Item Env:CONTAO_E2E_TRACE
```

## Database variants

To use Docker with a specific image:

```powershell
$env:CONTAO_E2E_DATABASE_TYPE = 'mysql'
$env:CONTAO_E2E_DATABASE_IMAGE = 'mysql:8.0'
vendor/bin/phpunit --testsuite=e2e
```

An explicit `CONTAO_E2E_DATABASE_URL` takes precedence over Docker. Remove that override to return to Docker provisioning.

See [database configuration](../testing/databases.md), [Playwright options](playwright.md) and [traces](traces.md). Use a ZIP utility with the documented archive root layout when [packaging a recipe](../recipes/archives.md).
