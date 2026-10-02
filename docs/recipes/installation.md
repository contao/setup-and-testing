# Installation behavior and runtime integration

The archive reader returns a `PortableInstallationRecipe`. `RecipeInstaller` applies it to an `InstallationTarget` containing a project directory, a Doctrine DBAL connection and an `InstallationRuntimeInterface` implementation.

The host runtime supplies two operations:

- `installDependencies(string $targetDirectory): void` resolves the merged Composer requirements and installs them in that project, updating its lock file as needed.
- `migrate(string $targetDirectory): void` invokes the application's migration runner.

The package does not depend on Contao core or Symfony Console. A Contao Manager plugin, importer or command can provide these operations. [Apply a recipe](../guides/apply-recipe.md) includes a complete CLI integration.

## Installation order

The installer validates file mappings, merges requirements into the existing `composer.json`, recursively merges ordered Symfony configuration fragments into `config/config.yaml`, installs dependencies when Composer changed, runs migrations, loads fixtures transactionally and copies files.

It writes `.contao-recipes/<vendor>--<recipe>.json` so later tooling can identify what the recipe changed. The journal records installation results. It does not provide automatic update, uninstall or rollback commands.

Only fixture loading is transactional. Composer installation, migrations and filesystem changes are not rolled back together if a later operation fails. Apply recipes to a prepared test installation first, and use your project's deployment and backup practices for existing installations.

Keep the archive open until installation has finished. Closing it removes the extracted temporary directory. The destructor is a fallback, while explicit `finally` cleanup keeps the lifetime clear.
