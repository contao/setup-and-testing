# Installation recipes and end-to-end testing

> **Development status:** These packages are still under very heavy development. There are no release tags yet, so require `dev-main` in Composer and expect breaking API changes. We plan a series of `0.x` releases while we work toward a stable API for Contao Core, extensions and regular web applications. [Feedback is always welcome](https://github.com/contao/setup-and-testing/issues).

This monorepo develops two packages on a shared version line that is independent of Contao core. Each package has a distinct responsibility:

| Package | Responsibility | Use it for |
| --- | --- | --- |
| [`contao/installation-recipe`](installation-recipe/) | Defines and applies portable recipes containing Composer requirements, configuration, database fixtures, and project files. The host application supplies operations such as dependency installation and migration. | Building an installer or importer that consumes recipes. |
| [`contao/e2e-testing`](e2e-testing/) | Provides browser testing for existing web applications and consumes recipes to provision isolated Contao Managed Editions with a database, installation cache, web server, and HTTP clients. | Testing Contao extensions, complete Contao projects, or other web applications from PHPUnit. |

The dependency goes from `contao/e2e-testing` to `contao/installation-recipe`. They live together so changes to the recipe model and its test consumer can be tested atomically. Both packages will be released independently of `contao/contao`, and the consuming project selects the Contao version to test.

## Documentation

Start with the [documentation site](https://contao.github.io/setup-and-testing/) or browse the [documentation source](docs/index.md) while Pages is being prepared. Guides cover testing extensions, monorepos and existing applications, as well as building and applying recipes.

Usage documentation is maintained only in the monorepo. The split packages contain short READMEs linking back to it.

## Repository layout and releases

[`monorepo.yml`](monorepo.yml) is the source of truth for splitting this repository and combining its Composer manifests:

| Setting | Purpose |
| --- | --- |
| `monorepo_url` | The Git remote for this source repository. |
| `branch_filter` | The branches eligible for splitting: `main`, numeric release branches such as `1.0`, and `feature/*`. |
| `repositories` | Maps each package directory to its split repository: [`e2e-testing`](https://github.com/contao/e2e-testing) and [`installation-recipe`](https://github.com/contao/installation-recipe). |
| `composer` | Extra settings for the combined root `composer.json`. Here, `bamarni/composer-bin-plugin` and Symfony HttpFoundation are root development dependencies. HttpFoundation verifies how Symfony interprets simulated origin headers in the test suite. The empty `require` and `conflict` lists add no constraints. |

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

During development, Composer's root `replace` and PSR-4 mappings expose both packages without publishing or configuring a path repository.

## Work on the documentation

Use an installed Python 3.8 or newer to create a virtual environment:

```shell
python3 -m venv .venv-docs
. .venv-docs/bin/activate
python -m pip install -r requirements-docs.txt
python -m mkdocs serve
```

Open the preview URL printed by MkDocs. If it reports `Address already in use`, another server occupies port 8000. Keep that server running and choose a free port:

```shell
python -m mkdocs serve --dev-addr 127.0.0.1:8766
```

If that port is also occupied, choose another. Stop your own preview with Ctrl+C before restarting it. Validate before sharing changes:

```shell
python -m mkdocs build --strict
```

See [documentation contribution instructions](docs/contributing.md) for Windows commands.
