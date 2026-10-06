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

`BrowserOptions` configures accepted languages and viewport dimensions for `createBrowser()` and `createBackendBrowser()`:

```php
$options = BrowserOptions::create()
    ->withAcceptLanguage('de-CH')
    ->withViewport(1440, 1200);

$browser = self::managedEdition()->createBrowser(options: $options);
```

Read configured values with `acceptLanguage()`, `viewportWidth()` and `viewportHeight()`. Every `with…()` method returns a clone.

For application-side origin simulation in BrowserKit, pass `HttpBrowserOptions::create()->withSimulatedOrigin('https://example.local')` to `createHttpBrowser()`. See [simulated public origins](frontend.md#simulate-a-public-origin) for setup. Playwright uses the configured application URL. To test a particular domain or HTTPS in a real browser, configure the test server and connect through `ApplicationConfig::create($url)`.
