# Contao backend interactions

Use `BackendBrowser` to log in, edit records and interact with Contao forms. It works in both testing modes. Browser selection and assertions work as described in [browsers and assertions](browsers.md). The underlying browser session is also available through `$backend->browser()`.

## Open the backend

These examples belong inside a test method. Use a test user created by your project's setup or recipe fixtures:

```php
$backend = self::managedEdition()->createBackendBrowser();
$backend->visit('/contao/login');
$backend->submitLogin('admin', 'password');
```

For an existing Contao project, wrap the generic browser explicitly. The helpers are the same:

```php
use Contao\E2eTesting\Browser\BackendBrowser;

$backend = new BackendBrowser(self::application()->createBrowser());
$backend->visit('/contao/login');
$backend->submitLogin('admin', 'password');
```

`createBackendBrowser()` is a Contao-specific convenience on Managed Editions. General applications provide `createBrowser()`.

## Work with records and forms

Use these helpers at the appropriate point in your backend workflow. Labels must match the backend's language:

| Helper | Action |
| --- | --- |
| `clickLink('Articles')` | Open a backend link |
| `submitNew()` | Start creating a record |
| `submitAction('Paste at the top')` | Choose a record operation |
| `check('published')` | Check a field |
| `fillRichText('text', 'Example content')` | Fill a rich-text editor |
| `submitForm('Save and close', ['headline[value]' => 'Headline'])` | Fill fields and submit the form |

Buttons and operation links can be matched by the start of their translated title. Access `$backend->page()` or `$backend->browser()->context()` when you need Playwright operations directly.

## Wait for dynamic fields

Dynamic Contao palettes finish asynchronously. Use `checkAndWaitForAjax()` or `selectAndWaitForAjax()` when changing a field causes Contao to rebuild part of the form. For extension-specific controls, `waitForAjax()` accepts the Playwright action that triggers the update:

```php
$backend->waitForAjax(
    static fn () => $backend->page()->locator('[data-action="load-widget"]')->click(),
);
$backend->waitFor('#extension_widget');
```

## Wait for custom navigation

The backend helpers wait for navigation automatically. When you click a custom link through Playwright, wrap the action in `waitForNavigation()`:

```php
$backend->waitForNavigation(
    static fn () => $backend->page()->getByRole('link', ['name' => 'Extension settings'])->click(),
);
```

Contao often updates the page through Turbo instead of loading a new document. `waitForNavigation()` handles either case and starts waiting before the action, so it also handles fast responses.

Use `waitForTurboNavigation()` when the action must render through Turbo, or `waitForFullNavigation()` when it must load a new document. Both register a navigation marker before running the action:

```php
$backend->waitForTurboNavigation(
    static fn () => $backend->page()->getByRole('link', ['name' => 'Articles'])->click(),
);
$backend->waitForFullNavigation(
    static fn () => $backend->page()->getByRole('link', ['name' => 'Log out'])->click(),
);
```

For updates that do not navigate, use Playwright locator assertions. Use `waitForAjax()` when Contao rebuilds part of a form.

## Select files

`selectFile($field, $path, $expectedValue)` opens Contao's file picker and expands directories to select the requested file. The optional expected value waits for the hidden widget field to contain a known UUID.

In Managed Editions, copied files may need registration in Contao's DBAFS first. See [file mappings](../recipes/configuration.md#copy-files-into-the-installation).
