# Installation behavior and runtime integration

The archive reader returns a `PortableInstallationRecipe`. `RecipeInstaller::plan()` prepares a read-only `RecipeInstallationPlan` for an `InstallationTarget` containing a project directory, a Doctrine DBAL connection and an `InstallationRuntimeInterface` implementation.

The host runtime supplies two operations:

- `installDependencies(string $targetDirectory): void` resolves the merged Composer requirements and installs them in that project, updating its lock file as needed.
- `migrate(string $targetDirectory): void` invokes the application's migration runner.

The package does not depend on Contao core or Symfony Console. A Contao Manager plugin, importer or command can provide these operations. [Apply a recipe](../guides/apply-recipe.md) includes a complete CLI integration.

## Review before installation

Call `$installer->plan($archive->recipe, $target)` and show `$plan->changes` to the person applying the recipe. The plan contains current Composer content and requested requirements, current configuration and ordered configuration fragments, fixture documents, requested and resolved file destinations with full source contents, content fingerprints and overwrite flags, and the previous journal content. Planning validates configuration fragments and fixtures without running Composer, migrations or database writes. Existing configuration is parsed only when configuration fragments will be merged, otherwise its contents are recorded without parsing.

After the host approves the concrete changes, call `$installer->install($plan, $target)`. The installer rejects a plan if the target directory, input contents or relevant target documents changed after review. Keep the archive open throughout review and installation. Serialize access to the installation directory. The plan detects stale inputs before installation but does not lock out concurrent filesystem changes.

Portable file mappings may install application files such as DCA, Symfony services and templates, including PHP. The importer does not evaluate those files. By default, Composer metadata, the merged configuration file, dependencies, Git metadata and journals are protected from file mappings. The host can choose its own protected destinations. Destination symlinks are resolved, including entries inside mapped directories and the Composer, configuration and journal destinations. Resolved paths must stay inside the installation. Copied files must satisfy the host destination policy at both the requested and resolved paths. Source mappings and archive entries must contain regular files and directories.

Overwriting application files requires an explicit host policy as well as the manifest flag:

```php
use Contao\InstallationRecipe\File\PortableFilePolicy;
use Contao\InstallationRecipe\Installation\RecipeInstallerFactory;

$installer = (new RecipeInstallerFactory())->create(
    (new PortableFilePolicy())
        ->withAllowedTargets(['files', 'contao/dca', 'config/services.php', 'templates'])
        ->withOverwrite(true),
);
```

A Managed Edition host that permits all application destinations except dependencies can use `(new PortableFilePolicy())->withProtectedTargets(['vendor'])`. This permission applies to resolved symlink destinations as well. It does not permit writes outside the installation. Mapping a file onto the installer's own Composer, configuration or journal output may conflict with those dedicated installation operations, so the host should account for installation order when enabling those destinations.

Allowed targets and protected targets are exact relative file paths or directory prefixes. Protection applies to both the configured paths and their resolved symlink destinations. For example, when `vendor` points to `dependencies`, mappings into `dependencies` are also protected. Omitting the host allowlist permits application destinations except the protected paths. An empty allowlist permits no copied files. Only enable overwrites after deciding that the recipe may replace the displayed destinations. Install recipes from publishers you trust. Dependencies, configuration and fixtures affect application behavior, and installed PHP runs with the application's permissions.

## Installation order

The installer revalidates the plan and file mappings, merges requirements into the existing `composer.json`, recursively merges ordered Symfony configuration fragments into `config/config.yaml`, installs dependencies when Composer changed, runs migrations, loads fixtures transactionally and copies files.

It writes `.contao-recipes/<vendor>--<recipe>.json` so later tooling can identify what the recipe changed. The journal records installation results. It does not provide automatic update, uninstall or rollback commands.

Only fixture loading is transactional. Composer installation, migrations and filesystem changes are not rolled back together if a later operation fails. Apply recipes to a prepared test installation first, and use your project's deployment and backup practices for existing installations.

Keep the archive open until installation has finished. Closing it removes the extracted temporary directory. The destructor is a fallback, while explicit `finally` cleanup keeps the lifetime clear.
