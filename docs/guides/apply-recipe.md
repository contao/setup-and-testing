# Apply a recipe

Use this guide when an installer or importer needs to apply a portable recipe archive to an existing Contao project. The host owns dependency installation, migrations and the database connection. The recipe package owns reading and validating the archive and applying its contents.

## Prerequisites

Use a prepared Contao test project with PHP, Composer, the ZIP extension and a configured MySQL/MariaDB database. Its normal console migration command must work. Start with [the example-theme archive](build-recipe.md#package-a-portable-recipe), or an archive with the [documented format](../recipes/archives.md).

In the target project, install the recipe package and Symfony Process, which this example uses to run the host commands:

```shell
composer require contao/installation-recipe symfony/process
```

Place the archive at the project root as `example-theme.zip`. Create `install-recipe.php` there:

```php
<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Contao\InstallationRecipe\Archive\RecipeArchive;
use Contao\InstallationRecipe\Installation\InstallationRuntimeInterface;
use Contao\InstallationRecipe\Installation\InstallationTarget;
use Contao\InstallationRecipe\Installation\RecipeInstaller;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Process\Process;

$databaseUrl = getenv('DATABASE_URL');

if (!$databaseUrl) {
    throw new RuntimeException('Set DATABASE_URL to the target project database.');
}

$runtime = new class implements InstallationRuntimeInterface {
    public function installDependencies(string $targetDirectory): void
    {
        $process = Process::fromShellCommandline(
            'composer update --no-interaction --no-progress',
            $targetDirectory,
        );
        $process->setTimeout(null);
        $process->mustRun();
    }

    public function migrate(string $targetDirectory): void
    {
        $process = new Process(
            [PHP_BINARY, 'vendor/bin/contao-console', 'contao:migrate', '--no-interaction'],
            $targetDirectory,
        );
        $process->setTimeout(null);
        $process->mustRun();
    }
};

$parameters = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($databaseUrl);
$connection = DriverManager::getConnection($parameters);

try {
    $archive = RecipeArchive::open(__DIR__.'/example-theme.zip');

    try {
        $target = new InstallationTarget(__DIR__, $connection, $runtime);
        $result = (new RecipeInstaller())->install($archive->recipe, $target);
        printf("Installed example page %s\n", $result->fixtures->value('example_page'));
    } finally {
        $archive->close();
    }
} finally {
    $connection->close();
}
```

The fixture lookup assumes the example-theme archive. Adapt the output for other recipes. This runtime runs `composer update` to resolve the recipe's merged requirements and update the project's lock file. The migration command must use the same database as the supplied DBAL connection.

## Run the installer

Set the connection for your prepared target database, then run:

```shell
DATABASE_URL='mysql://user:password@127.0.0.1:3306/contao_test' php install-recipe.php
```

PowerShell:

```powershell
$env:DATABASE_URL = 'mysql://user:password@127.0.0.1:3306/contao_test'
php install-recipe.php
```

For the example archive, the script prints the generated page identifier. Inspect the target's configuration, installed files and `.contao-recipes/` journal. The extracted archive directory is removed even if installation fails.

This operation modifies the target installation. Read [installation order and failure behavior](../recipes/installation.md) before applying a recipe to an existing project. Only the fixture import is transactional.
