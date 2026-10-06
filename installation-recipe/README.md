# Contao installation recipes

> **Development status:** These packages are still under very heavy development. There are no release tags yet, so require `dev-main` in Composer and expect breaking API changes. We plan a series of `0.x` releases while we work toward a stable API for Contao Core, extensions and regular web applications. [Feedback is always welcome](https://github.com/contao/setup-and-testing/issues).

`contao/installation-recipe` describes reusable Composer requirements, configuration, database fixtures and project files, and applies portable recipe archives through a host application's runtime.

```shell
composer require contao/installation-recipe:dev-main
```

Choose your starting point in the shared documentation:

- [Build a reusable recipe](https://contao.github.io/setup-and-testing/guides/build-recipe/)
- [Apply a recipe](https://contao.github.io/setup-and-testing/guides/apply-recipe/)
- [Portable archive format](https://contao.github.io/setup-and-testing/recipes/archives/)
- [Database fixtures](https://contao.github.io/setup-and-testing/recipes/fixtures/)

The [documentation source](https://github.com/contao/setup-and-testing/tree/main/docs) is available in the monorepo, including before GitHub Pages is activated. A sample archive source remains in [examples/example-theme](examples/example-theme/). Development and contributions belong in [contao/setup-and-testing](https://github.com/contao/setup-and-testing).
