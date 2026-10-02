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

### [Test a web application](guides/web-application.md)

Test an application in a single repository or monorepo, using a local server or an existing URL.

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

Choose whether the tooling should build an isolated Contao installation or test your own application. The Contao project and general web application guides use the same application testing tools.

| Responsibility | Managed Edition | Your application |
| --- | --- | --- |
| Use it for | A Contao extension or several local Contao packages | A complete application, local or already served at a URL |
| Installation | Created by the tooling | Prepared by your project |
| Web server | Started by the tooling | Started by the tooling when configured, or provided through a URL |
| Database setup and resets | Managed by the tooling using your recipe | Handled by your project's test setup |
| Browser sessions and assertions | Managed by the tooling | Managed by the tooling |

A **Managed Edition** is an isolated Contao installation built from a recipe for your tests. For **your application**, use a local server configuration or an existing URL, including for Contao projects. Contao backend helpers work in both modes.

Recipes can also be created and applied independently of testing. These docs follow `main`. Check the [requirements](reference/requirements.md) for supported versions.
