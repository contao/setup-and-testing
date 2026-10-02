# Installation recipes and end-to-end testing

Create and apply reusable installation recipes, or test applications with PHPUnit and a real browser. Choose the task that matches your project.

## What do you want to do?

<div class="use-case-grid" markdown="1">

<div class="use-case-card" markdown="1">

### [Test a Contao extension](guides/contao-extension.md)

Run your local bundle in an isolated Contao installation with a managed server and database.

</div>

<div class="use-case-card" markdown="1">

### [Test Contao packages in a monorepo](guides/monorepo.md)

Test local Contao packages together in an isolated Contao installation.

</div>

<div class="use-case-card" markdown="1">

### [Test an existing Contao project](guides/contao-project.md)

Test a running Contao project with frontend assertions and backend helpers.

</div>

<div class="use-case-card" markdown="1">

### [Test an existing web application](guides/web-application.md)

Test a running application in a single repository or monorepo, whatever its framework or language.

</div>

<div class="use-case-card" markdown="1">

### [Build a reusable recipe](guides/build-recipe.md)

Describe dependencies, configuration, fixtures and files for tests or portable installation archives.

</div>

<div class="use-case-card" markdown="1">

### [Apply a recipe](guides/apply-recipe.md)

Integrate a recipe archive into an installer and apply it to a prepared project.

</div>

</div>

## Who runs the application?

There are two testing modes. The Contao project and general web application guides use the same URL-based mode.

| Responsibility | Managed Edition | Existing application |
| --- | --- | --- |
| Use it for | A Contao extension or several local Contao packages | A complete application already served at a URL |
| Installation and web server | Created and started by the tooling | Provided by your project or test environment |
| Database setup and resets | Managed by the tooling using your recipe | Handled by your project's test setup |
| Browser sessions and assertions | Managed by the tooling | Managed by the tooling |

A **Managed Edition** is an isolated Contao installation built from a recipe for your tests. An **existing application** is any application you connect to through its URL, including Contao. Contao backend helpers work in both modes.

Recipes can also be created and applied independently of testing. These docs follow `main`. Check the [requirements](reference/requirements.md) for supported versions.
