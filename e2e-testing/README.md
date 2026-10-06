# Contao E2E testing

> **Development status:** These packages are still under very heavy development. There are no release tags yet, so require `dev-main` in Composer and expect breaking API changes. We plan a series of `0.x` releases while we work toward a stable API for Contao Core, extensions and regular web applications. [Feedback is always welcome](https://github.com/contao/setup-and-testing/issues).

`contao/e2e-testing` provides PHPUnit browser testing for existing web applications and isolated Contao Managed Editions built from installation recipes.

```shell
composer require --dev contao/e2e-testing:dev-main contao/installation-recipe:dev-main
```

Require both packages explicitly so Composer permits their development versions.

Choose your starting point in the shared documentation:

- [Test a Contao extension](https://contao.github.io/setup-and-testing/guides/contao-extension/)
- [Test Contao packages in a monorepo](https://contao.github.io/setup-and-testing/guides/monorepo/)
- [Test an existing Contao project](https://contao.github.io/setup-and-testing/guides/contao-project/)
- [Test a web application](https://contao.github.io/setup-and-testing/guides/web-application/)

The [documentation source](https://github.com/contao/setup-and-testing/tree/main/docs) is available in the monorepo, including before GitHub Pages is activated. Development and contributions belong in [contao/setup-and-testing](https://github.com/contao/setup-and-testing).
