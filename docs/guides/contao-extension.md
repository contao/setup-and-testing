# Test a Contao extension

Use this guide when your repository contains a Contao bundle and you want to test its current working tree in a clean Contao installation. The tooling creates an isolated Contao installation, called a Managed Edition. It starts the web server and restores your recipe fixtures between tests, so you do not need to run a Contao project yourself.

## Prerequisites

You need [the supported PHP and Node.js versions](../reference/requirements.md), Composer and Git. Start Docker with Linux containers, or configure an [administrative MySQL/MariaDB connection](../testing/databases.md#use-an-existing-database-server). Run the commands from your bundle root. On Windows, follow [Windows setup](../running/windows.md).

## Install the tooling

```shell
composer require --dev contao/e2e-testing
vendor/bin/playwright-install --browsers
```

Ignore `.contao-e2e/` in your bundle's `.gitignore`. It contains cached installations, Docker database files and runtime data.

## Add your first test

Put this test in `tests/E2e/ManagedEditionSmokeTest.php`. Replace `acme/example-bundle` with the `name` from your bundle's `composer.json`. Choose a Contao constraint your bundle supports. The example assigns the local bundle version `1.0.x-dev`, which must satisfy any requirements on that bundle. It does not need to match your Git branch name.

```php
<?php

declare(strict_types=1);

use Contao\E2eTesting\ManagedEdition\AbstractManagedEditionTestCase;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;

final class ManagedEditionSmokeTest extends AbstractManagedEditionTestCase
{
    protected static function createApplicationConfig(): ManagedEditionConfig
    {
        $bundleRoot = dirname(__DIR__, 2);
        $composer = ComposerConfig::managedEdition('^5.7')
            ->withPathPackage('acme/example-bundle', $bundleRoot, '1.0.x-dev');

        return ManagedEditionConfig::create(InstallationRecipe::create($composer), $bundleRoot);
    }

    public function testBackendLoginPage(): void
    {
        $backend = self::managedEdition()->createBackendBrowser();
        $backend->visit('/contao/login');

        $this->assertSelectorExists('input[name="username"]');
    }
}
```

Add `tests/E2e` to your existing PHPUnit test suite, or use this minimal `phpunit.xml.dist` in the bundle root:

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

From the bundle root, run:

```shell
vendor/bin/phpunit --configuration=phpunit.xml.dist tests/E2e/ManagedEditionSmokeTest.php
```

`withPathPackage()` makes Composer require your bundle from its local directory and symlink it into the Managed Edition's `vendor/`. The test sees the current working tree, including uncommitted PHP changes. Source changes invalidate the cached application setup on the next test process. Changes to a linked bundle's `composer.json` select a fresh dependency installation so Composer resolves the new requirements.

## Expected result and next steps

PHPUnit reports a passing test after opening the backend login page. The first run installs Contao and may take longer than later cached runs. This test does not require an existing backend user. Replace its assertion with a check for your bundle's behavior once it passes.

Add [recipe fixtures](../recipes/fixtures.md) for data-dependent tests, [test-specific DCA](../testing/dca.md) for widgets, or [frontend tests](../testing/frontend.md). Continue with [backend interactions](../testing/backend.md) and [CI setup](../running/ci.md).
