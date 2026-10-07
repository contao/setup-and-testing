# Inspect an E2E application manually

Use `server:start` to prepare the same application as your tests and keep it available in your own browser at a chosen state. The inspection file can return any `ApplicationConfigInterface`, or a callable receiving `ApplicationRuntime` and returning a configuration or a prepared `ApplicationInterface`.

Supported setups include Managed Editions, local PHP servers, custom server commands and externally hosted applications. Managed Editions install the recipe, apply fixtures and hold the installation and Docker database leases until you stop the session, so tests running alongside them use a separate installation slot.

Run from your extension or project root:

```shell
vendor/bin/contao-e2e server:start tests/inspection.php
```

The command prints the application URL. For Managed Editions it also prints the Contao backend URL, installation directory and database connection URL. Open the displayed URL in your browser. The inspection file is resolved from the current working directory, while paths inside the file can use `__DIR__` as usual. Composer's autoloader is already loaded.

## Inspect a local application

For an existing application with a `public` document root, create `tests/inspection.php`:

```php
<?php

use Contao\E2eTesting\Application\LocalApplicationConfig;

return LocalApplicationConfig::php(dirname(__DIR__))
    ->withEnvironment(['APP_ENV' => 'test']);
```

This starts a PHP server on an available port and stops that server when inspection ends. You can configure the document root, router, environment and [PHP server settings](../testing/webservers.md#configure-the-spawned-php-process) through the normal local application configuration.

For another server process, use a command containing a `{port}` placeholder:

```php
<?php

use Contao\E2eTesting\Application\LocalApplicationConfig;

return LocalApplicationConfig::command(
    [PHP_BINARY, '-S', '127.0.0.1:{port}', '-t', 'public'],
    dirname(__DIR__),
);
```

The framework substitutes an available port, waits for the server to listen on the assigned port and owns the spawned process until inspection stops. Your command can start any HTTP application that listens on `127.0.0.1` at the assigned port.

## Inspect an externally hosted application

Return an `ApplicationConfig` to connect to a server that is already running:

```php
<?php

use Contao\E2eTesting\Application\ApplicationConfig;

return ApplicationConfig::create('http://localhost:8080');
```

The inspection session owns no server in this case. Stopping it releases its browser resources and leaves the external application running. A preparation callable can make HTTP requests or use Playwright against that application before returning it.

## Inspect a Managed Edition

Create `tests/inspection.php` with a configuration for the Contao version and local extension you want to inspect:

```php
<?php

declare(strict_types=1);

use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;

$projectRoot = dirname(__DIR__);
$composer = ComposerConfig::managedEdition('^6.0')
    ->withPathPackage('acme/example-bundle', $projectRoot, '1.0.x-dev');
$recipe = InstallationRecipe::create($composer)
    ->withFixtureFile(__DIR__.'/Fixtures/inspection.yaml');

return ManagedEditionConfig::create($recipe, $projectRoot);
```

Choose a Contao constraint supported by your extension and replace the package name and fixture path. The normal [database configuration](../testing/databases.md), [reusable installation cache](../testing/caching.md) and [PHP server defaults](../testing/webservers.md#configure-the-spawned-php-process) apply. Backend users and passwords come from your fixtures. Your own browser does not inherit Playwright's login session.

Extract configuration shared by PHPUnit and inspection into a factory in your project's autoloaded test support code. Both entry points can then use that factory instead of maintaining two recipes.

## Prepare an intermediate state

The file can also return a callable receiving `ApplicationRuntime`. It may return an `ApplicationConfigInterface` or a prepared `ApplicationInterface`. Use the supplied runtime when creating the application:

```php
<?php

use Contao\E2eTesting\Application\ApplicationInterface;
use Contao\E2eTesting\Application\ApplicationRuntime;
use Contao\E2eTesting\Http\HttpRequest;

return static function (ApplicationRuntime $runtime): ApplicationInterface {
    // This file returns the configuration shared with your tests.
    $config = require __DIR__.'/application-config.php';
    $application = $config->createApplication($runtime);

    // Run the same scenario preparation as your test before returning the application.
    $application->send(HttpRequest::get('/'));

    return $application;
};
```

Preparation completes before the command announces that the application is ready. The inspection session does not reset application state between your manual requests. If the callback uses Playwright, prepare its dependencies and browsers as you would for your tests.

## Run in the background

Use the same inspection file with `-d` or `--daemon`:

```shell
vendor/bin/contao-e2e server:start tests/inspection.php -d
vendor/bin/contao-e2e server:status
vendor/bin/contao-e2e server:stop
```

The start command returns after the worker starts. Preparation continues in the background. `server:status` reports `starting` until preparation finishes, then `running` with the application URL. Managed Edition sessions also show the backend URL, installation directory and database connection URL. The worker uses your project's Composer autoloader, working directory and environment.

One background session can run per E2E workspace. A second start refuses while that session is active. Run status and stop from the same project root with the same `CONTAO_E2E_DIRECTORY` setting. Foreground sessions still stop through their own terminal.

The log path is printed by start and status. Logs and session state live under `.contao-e2e/runtime/inspection`, or the corresponding custom workspace. If preparation fails, status reports `failed` and exits with code 1. A worker that exits unexpectedly leaves a `stale` session, also reported with code 1. You can start another session after the previous worker has exited. Starting again replaces the previous log. On Windows, standard error is written to `worker.log.error` alongside `worker.log`.

Daemon mode works on Linux and macOS using `nohup`, and on Windows using PowerShell's `Start-Process`. `server:stop` requests cleanup and waits up to 30 seconds. With PCNTL in the worker and POSIX in the stop command, a stop request also interrupts preparation. Otherwise preparation must finish before the worker can handle the request. If shutdown is still pending, the command exits with code 1 and the request remains in place. Check status and retry stop later.

## Stop the session

Press Enter to release the application and stop any server owned by the inspection session. Managed Editions also release their installation lease. On systems with PHP's PCNTL extension, Ctrl+C and SIGTERM also shut down cleanly, including during recipe or scenario preparation. On Windows, use Enter once preparation finishes. Closing redirected input also ends an interactive session.

Docker databases stop when the process exits and no other process holds a lease for that database. Shared databases remain running while another test or inspection process uses them. Cached installations and database storage remain available for subsequent runs.

Without `-d`, the command runs in the foreground. With `--no-interaction`, it ignores standard input and waits for a shutdown signal, which requires PCNTL. Background workers accept `server:stop` without PCNTL. Stopping a background session that has already exited succeeds without affecting other processes.
