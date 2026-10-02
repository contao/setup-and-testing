# Test Contao packages in a monorepo

Use this guide when several Composer packages live in one repository and should be tested together in an isolated Contao installation. The root installs the test tooling, while `MonorepoProject` adds selected local packages to the Managed Edition.

For a monorepo containing another kind of web application, follow [Test an existing web application](web-application.md#use-this-in-a-monorepo). Your repository layout does not require a Managed Edition.

## Prerequisites and layout

Install the [supported PHP and Node.js versions](../reference/requirements.md), Composer and Git. Start Docker with Linux containers, or configure an [administrative database connection](../testing/databases.md#use-an-existing-database-server). The tooling starts Contao's web server automatically.

This example assumes:

```text
composer.json
packages/
    example-bundle/composer.json
    shared-bundle/composer.json
tests/E2e/MonorepoSmokeTest.php
phpunit.xml.dist
```

Each package manifest must have a `name`. The shared local version must satisfy constraints between the packages. For example, add this root Composer setting when local packages require each other with `^1.0`:

```json
{
    "extra": {
        "branch-alias": {
            "dev-main": "1.0.x-dev"
        }
    }
}
```

Merge that setting into your existing root manifest. An explicit root `version` takes precedence over the alias. Without either setting, discovery uses `dev-main`. It reads package names from the selected local manifests.

## Install and configure

Run from the monorepo root:

```shell
composer require --dev contao/e2e-testing
vendor/bin/playwright-install --browsers
```

Add `.contao-e2e/` to the root `.gitignore`. Put this in `tests/E2e/MonorepoSmokeTest.php`:

```php
<?php

declare(strict_types=1);

use Contao\E2eTesting\Composer\MonorepoProject;
use Contao\E2eTesting\ManagedEdition\AbstractManagedEditionTestCase;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;

final class MonorepoSmokeTest extends AbstractManagedEditionTestCase
{
    protected static function createApplicationConfig(): ManagedEditionConfig
    {
        $root = dirname(__DIR__, 2);
        $project = MonorepoProject::discover($root);
        $composer = $project->configureComposer(
            ComposerConfig::managedEdition('^5.7'),
            'packages/example-bundle',
            'packages/shared-bundle',
        );

        return ManagedEditionConfig::create(InstallationRecipe::create($composer), $root);
    }

    public function testBackendLoginPage(): void
    {
        self::managedEdition()->createBackendBrowser()->visit('/contao/login');
        $this->assertSelectorExists('input[name="username"]');
    }
}
```

Replace the package directories and choose a Contao constraint supported by them. Add this root PHPUnit configuration, or merge its test suite into your existing configuration:

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

```shell
vendor/bin/phpunit --testsuite=e2e
```

PHPUnit should pass after loading the backend login page with both local packages installed. Each test class chooses its packages and recipe. Share that configuration through a project-specific abstract test class when several classes need it, rather than repeating the package list.

Local packages are linked from their working trees. Changes to package manifests select a fresh dependency installation. Other source changes refresh application setup on the next process. See [caching and isolation](../testing/caching.md), [Docker warm-up](../testing/databases.md) and [CI caches](../running/ci.md).
