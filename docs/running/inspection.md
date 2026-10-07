# Inspect a Managed Edition manually

Use `server:start` to prepare the same Managed Edition as your tests and keep it available in your own browser. The command installs the recipe, applies fixtures and starts the HTTP server. It holds the installation and Docker database leases until you stop the session, so tests running alongside it use a separate installation slot.

Run from your extension or project root:

```shell
vendor/bin/contao-e2e server:start tests/inspection.php
```

The command prints the frontend and backend URLs, installation directory and database connection URL. Open the displayed URL in your browser. The inspection file is resolved from the current working directory, while paths inside the file can use `__DIR__` as usual. Composer's autoloader is already loaded.

## Return a configuration

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

The file can also return a callable receiving `ApplicationRuntime`. It may return a configuration or a prepared `ManagedEdition`. Use the supplied runtime when creating the edition:

```php
<?php

use Contao\E2eTesting\Application\ApplicationRuntime;
use Contao\E2eTesting\Http\HttpRequest;
use Contao\E2eTesting\ManagedEdition\ManagedEdition;

return static function (ApplicationRuntime $runtime): ManagedEdition {
    // This file returns the configuration shared with your tests.
    $config = require __DIR__.'/edition-config.php';
    $edition = $config->createApplication($runtime);

    // Run the same scenario preparation as your test before returning the edition.
    $edition->send(HttpRequest::get('/'));

    return $edition;
};
```

Preparation completes before the command announces that the edition is ready. The inspection session does not reset application state between your manual requests. If the callback uses Playwright, prepare its dependencies and browsers as you would for your tests.

## Stop the session

Press Enter to stop the HTTP server and release the installation. On systems with PHP's PCNTL extension, Ctrl+C and SIGTERM also shut down cleanly, including during recipe or scenario preparation. On Windows, use Enter once preparation finishes. Closing redirected input also ends an interactive session.

Docker databases stop when the process exits and no other process holds a lease for that database. Shared databases remain running while another test or inspection process uses them. Cached installations and database storage remain available for subsequent runs.

The command runs in the foreground. With `--no-interaction`, it ignores standard input and waits for a shutdown signal, which requires PCNTL. It does not provide daemon mode or a separate `server:stop` command.
