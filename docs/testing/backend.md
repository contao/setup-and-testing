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
use Contao\E2eTesting\Browser\BackendLoginSessionCache;
use Contao\E2eTesting\Browser\Session\CookieSessionStorage;
use Contao\E2eTesting\Browser\Session\SessionCache;

$application = self::application();
$sessions = new SessionCache($application->runtime()->cache, $application->uri(), new CookieSessionStorage());
$backend = new BackendBrowser(
    $application->createBrowser(),
    new BackendLoginSessionCache($application->runtime()->cache, $sessions),
);
$backend->visit('/contao/login');
$backend->submitLogin('admin', 'password');
```

`createBackendBrowser()` is a Contao-specific convenience on Managed Editions. General applications provide `createBrowser()`.

## Reuse authenticated sessions

Use `loginOrReuseSessionAs()` when the login itself is not part of the behavior under test:

```php
$backend = self::managedEdition()
    ->createBackendBrowser()
    ->loginOrReuseSessionAs()
;
$backend->visit('/contao?do=article');
```

The username defaults to `k.jones` and the password to `kevinjones`. Pass another username and password as arguments:

```php
$backend->loginOrReuseSessionAs('content-editor', 'backend');
```

The first call submits the normal login form. Later calls restore the authenticated cookies and open `/contao`. If Contao rejects the session, the helper logs in again. An unsuccessful login throws a `RuntimeException`. The helper returns the same `BackendBrowser` for chaining and requires a user that can log in without two-factor authentication.

The underlying [session services](browsers.md#cache-and-restore-sessions) can be used with any web application. Only the login form and authenticated-user verification are specific to Contao.

Managed Editions configure PHP session-file storage, which caches a clean copy of the matching PHP session files under `var/sessions`. Each reuse restores that copy with a fresh session ID, so database resets and server restarts can keep the authentication without carrying subsequent session changes into the next test. Sessions are separated by installation, application environment, username, password and browser user agent. The cache lives in memory for the test process and can span test classes using the same installation. Session reuse has been verified with Contao 5.3, 5.7 and 6.0. Browser and Managed Edition integration checks are opt-in for local runs.

For an existing Contao project, use the explicit browser and cache construction shown above, then call the same helper. Its cache is scoped to the application URL and restores cookies only. If your setup clears server-side sessions, or a Managed Edition uses a custom session handler outside `var/sessions`, the next call falls back to logging in. Each call replaces the cookies sent to the backend with those of the requested login. Cookies for other sites and paths are preserved.

Continue using `visit('/contao/login')` and `submitLogin()` for tests that exercise authentication, logout, two-factor authentication or login side effects such as updating the last-login timestamp.

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
