# Contao setup and testing

This monorepo develops two packages on a shared version line that is independent of Contao core. Each package has a distinct responsibility:

| Package | Responsibility | Use it for |
| --- | --- | --- |
| [`contao/installation-recipe`](installation-recipe/) | Defines and applies portable recipes containing Composer requirements, configuration, database fixtures, and project files. The host application supplies operations such as dependency installation and migration. | Building an installer or importer that consumes recipes. |
| [`contao/e2e-testing`](e2e-testing/) | Consumes those recipes to provision isolated Contao Managed Editions for tests, with a database, installation cache, web server, and HTTP and browser clients. | Testing a Contao project through HTTP requests or a real browser from PHPUnit. |

The dependency goes from `contao/e2e-testing` to `contao/installation-recipe`. They live together so changes to the recipe model and its test consumer can be tested atomically. Both packages are released independently of `contao/contao`, and the consuming project selects the Contao version to test.

## Repository layout and releases

[`monorepo.yml`](monorepo.yml) is the source of truth for splitting this repository and combining its Composer manifests:

| Setting | Purpose |
| --- | --- |
| `monorepo_url` | The Git remote for this source repository. |
| `branch_filter` | The default branches eligible for splitting: `main` and numeric release branches such as `1.0`. |
| `repositories` | Maps each package directory to its split repository: [`e2e-testing`](https://github.com/contao/e2e-testing) and [`installation-recipe`](https://github.com/contao/installation-recipe). |
| `composer` | Extra settings for the combined root `composer.json`. Here, `bamarni/composer-bin-plugin` is a root development dependency. The empty `require` and `conflict` lists add no constraints. |

Work on both packages in this repository. The [split workflow](.github/workflows/split.yml) runs on pushes and passes the pushed branch or tag to `monorepo-tools`, which publishes each package directory to its configured repository. The package `composer.json` files remain alongside their source, while `monorepo-tools composer-json --validate` checks that the root Composer manifest represents both packages and the additions in `monorepo.yml`.

## Development

Install the root and tool dependencies, then run the local quality suite:

```shell
composer install
composer install --working-dir=vendor-bin/rector
composer install --working-dir=vendor-bin/ecs
composer install --working-dir=vendor-bin/phpstan
composer install --working-dir=vendor-bin/monorepo-tools
composer all
```

`composer all` runs Rector and ECS with automatic fixes, followed by PHPUnit, PHPStan, and the monorepo Composer validation. [GitHub Actions](.github/workflows/ci.yml) checks the formatting tools without applying changes, validates all three Composer manifests, tests the supported PHP, Symfony, and PHPUnit combinations, and runs YAMLlint.

Both package directories contain focused usage documentation. During development, Composer's root `replace` and PSR-4 mappings expose both packages without publishing or configuring a path repository.
