# Browsers and assertions

Install the Playwright browser binaries once after requiring the package:

```shell
vendor/bin/playwright-install --browsers
```

Use `vendor/bin/playwright-install --with-deps --browsers` on a fresh Linux CI runner to install the required system libraries as well. Playwright caches matching Chromium, Firefox, and WebKit binaries outside the project and reuses them between runs.

## Create a browser

Use `self::application()->createBrowser()` in existing-application tests and `self::managedEdition()->createBrowser()` in Managed Edition tests. Firefox is the default. Select Chromium or WebKit with `BrowserType`:

```php
use Contao\E2eTesting\Browser\BrowserType;

$browser = self::application()->createBrowser(BrowserType::Chromium);
$browser->visit('/');
$this->assertSelectorExists('h1');
$this->assertSelectorTextContains('h1', 'Welcome');
```

Put the `use` statements at the top of your test file and the browser actions inside a test method. The selector helpers use the most recently created browser session. Use `$browser->page()` for Playwright locators, `$browser->context()` for context operations and `$browser->uri('/login')` to construct a URL. Relative paths are appended to the configured application base path. An absolute HTTP or HTTPS URL is used as supplied.

Each browser call creates an independent context with its own cookies and storage. Represent two users by creating two sessions and using their pages directly. Playwright locators wait automatically for actionable elements, so assertions do not need fixed sleeps.

## Set the browser language

Use `BrowserOptions` to send an `Accept-Language` header with browser requests. The same options work in both testing modes:

```php
use Contao\E2eTesting\Browser\BrowserOptions;

$options = BrowserOptions::create()->withAcceptLanguage('de-CH,de,en');
$backend = self::managedEdition()->createBackendBrowser(options: $options);
// The header works with Chromium, Firefox, and WebKit.
```

See [Contao backend interactions](backend.md), [Playwright options](../running/playwright.md) and [traces](../running/traces.md) for more control.

## Configure browser options

`BrowserOptions` configures accepted languages, viewport dimensions and video dimensions for `createBrowser()` and `createBackendBrowser()`:

```php
$options = BrowserOptions::create()
    ->withAcceptLanguage('de-CH')
    ->withViewport(1440, 1200);

$browser = self::managedEdition()->createBrowser(options: $options);
```

Read configured values with `acceptLanguage()`, `viewportWidth()` and `viewportHeight()`. Every `with…()` method returns a clone.

When `PW_VIDEOS_DIR` enables recording, videos match the initial viewport by default. Use `withVideoSize($width, $height)`
to choose explicit recording dimensions, and read them with `videoWidth()` and `videoHeight()`. See [video recording](../running/playwright.md#record-videos)
for environment options, precedence, sharing and browser limitations.

For application-side origin simulation in BrowserKit, pass `HttpBrowserOptions::create()->withSimulatedOrigin('https://example.local')` to `createHttpBrowser()`. See [simulated public origins](frontend.md#simulate-a-public-origin) for setup. Playwright uses the configured application URL. To test a particular domain or HTTPS in a real browser, configure the test server and connect through `ApplicationConfig::create($url)`.

## Cache and restore sessions

The services in `Contao\E2eTesting\Browser\Session` work with any web application. `SessionCache` stores cookie snapshots in the application's in-memory cache. Choose `CookieSessionStorage` for a remote application, or `PhpSessionStorage` when you can access a local PHP application's session files:

```php
use Contao\E2eTesting\Browser\Session\PhpSessionStorage;
use Contao\E2eTesting\Browser\Session\SessionCache;

$application = self::application();
$sessions = new SessionCache(
    $application->runtime()->cache,
    '/path/to/test-installation',
    new PhpSessionStorage('/path/to/test-installation/var/sessions'),
);
$browser = $application->createBrowser();
$browser->visit('/login');
// Complete your application's login or other session setup here.
$userAgent = $browser->page()->evaluate('() => navigator.userAgent');

if (!is_string($userAgent)) {
    throw new RuntimeException('Could not determine the browser user agent.');
}

$key = $sessions->key('example-state', $userAgent);
$sessions->save($key, $browser->context()->cookies([$browser->uri()]));

$nextBrowser = $application->createBrowser();
$snapshot = $sessions->get($key);

if ($snapshot !== null) {
    $nextBrowser->context()->addCookies($sessions->restore($snapshot));
}

$nextBrowser->visit('/');
```

Use an application or installation identifier as the cache scope, and include differences such as credentials or fixture variants in the state identifier passed to `key()`. Use the same browser user agent for capture and reuse. Call `forget($key)` to invalidate a saved snapshot. The application decides how to establish the session, verify the restored state and recover if the server rejects it.

`CookieSessionStorage` restores only cookies. `PhpSessionStorage` also snapshots matching `sess_*` files beneath the configured directory, including environment subdirectories. It restores them with fresh session IDs so subsequent changes stay isolated between browser contexts. Cookie names are discovered from the browser, including custom `session.name` settings. `SessionCache` requires an explicit storage strategy, and additional handlers can implement `SessionStorageInterface`.

The PHP session directory must be the one used by the server. The parent PHP process can have different INI settings. For local servers, configure `session.name` and `session.save_path` through [PHP server configuration](webservers.md). This file storage strategy supports ordinary session directories and environment subdirectories. PHP's hashed directory layout configured with `N;MODE;/path` in `session.save_path`, Redis sessions and database sessions require another storage implementation. With cookie-only storage, the server must still have the original session.
