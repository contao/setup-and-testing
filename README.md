# Contao setup and testing

This monorepo develops two packages on a shared version line that is independent of Contao core. They provide the building blocks required to create reproducible Contao installations:

- [`contao/installation-recipe`](installation-recipe/) describes Composer requirements, configuration fragments, relational database fixtures, and project files without depending on Contao or a test framework.
- [`contao/e2e-test-bundle`](e2e-test-bundle/) turns such a recipe into an isolated Contao Managed Edition with a migrated database, reusable installation cache, web server, BrowserKit, and Panther clients.

The packages live together because the E2E runtime is a direct consumer of installation recipes and changes can be tested atomically. They are released from this repository independently of `contao/contao`. This lets recipes evolve for non-testing use cases such as theme import, while the E2E bundle can test any supported Contao version selected by the consuming project.

## Development

Install the root dependencies and run the complete local CI suite:

```shell
composer install
composer all
```

The suite runs Rector, ECS, PHPUnit, PHPStan, and the monorepo Composer validation. GitHub Actions additionally verifies the supported PHP, Symfony, Doctrine DBAL, and PHPUnit combinations and validates YAML files.

Both package directories contain focused usage documentation. During development, Composer's root `replace` and PSR-4 mappings expose both packages without publishing or configuring a path repository.
