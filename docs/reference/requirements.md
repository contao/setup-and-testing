# Requirements

## Recipe creation and installation

- PHP 8.2 or newer and the ZIP extension.
- Composer for package installation and dependency resolution.
- Doctrine DBAL 3.6 or 4.x, with the appropriate database driver for fixture loading.
- Symfony Filesystem and YAML 6.4, 7.x or 8.x as resolved by Composer.
- A host application that supplies dependency installation and migrations when applying archives.

The recipe package does not require Contao core, PHPUnit or browser tooling.

## E2E tests

- PHP 8.2 or newer, Composer and the extensions required by the tested application.
- PHPUnit 10.5, 11.5, 12.x or 13.x. Newer PHPUnit versions require newer PHP versions, so choose a combination Composer can resolve.
- Node.js 20 or newer and installed Playwright browser binaries for browser tests.
- Playwright PHP 1.5 or newer within the package's Composer constraint.
- Supported Symfony components from the 6.4, 7.x or 8.x lines, subject to their PHP requirements.

Managed Edition tests also need Git, Docker with Linux containers or an administrative MySQL/MariaDB server, and Contao's required PHP extensions such as `intl`, `mbstring` and `pdo_mysql`. The recipe selects the Contao version. The testing package does not require a specific Contao bundle version.

Tests for your own application can start a local server or connect to an existing URL. Your project prepares application dependencies and database state. PHPUnit still runs in PHP when the application uses another language.

See [Windows setup](../running/windows.md) and [CI setup](../running/ci.md) for platform instructions. The Composer manifests remain authoritative for supported dependency constraints.
