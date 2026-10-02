# Frontend tests and origins

This page covers frontend testing in a Managed Edition. For an existing project, use its URL as shown in [the application guide](../guides/web-application.md). The same isolated Contao installation can serve frontend and backend tests. Prepare the page structure and content through recipe fixtures, and add project files such as templates and assets through recipe file mappings. Then visit a frontend URL with the generic browser:

```php
$browser = self::managedEdition()->createBrowser();
$browser->visit('/');

$this->assertSelectorTextContains('h1', 'Welcome');
```

## Emulate a page domain

In a Managed Edition test, import `Contao\E2eTesting\Http\Origin` and pass the origin by name:

```php
use Contao\E2eTesting\Http\Origin;

$browser = self::managedEdition()->createBrowser(origin: Origin::https('example.test'));
$browser->visit('/');
```

The server maps that origin without requiring a real domain or certificate. Without an origin, Playwright uses the local server URI directly, keeping absolute redirects and cookies on the same browser origin. Use an explicit origin when fixtures require a page DNS entry or HTTPS.

## Test without JavaScript

For HTTP tests without JavaScript, use Symfony's BrowserKit client:

```php
$browser = self::managedEdition()->createHttpBrowser(Origin::https('example.test'));
$crawler = $browser->request('GET', '/');

$this->assertSame(200, $browser->getInternalResponse()->getStatusCode());
$this->assertSame('Example', trim($crawler->filterXPath('//head/title')->text()));
```

For an existing application, use its actual URL and infrastructure. Origin emulation and the BrowserKit factory above belong to Managed Edition mode.
