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

## Test HTTP and JSON endpoints

Both Managed Editions and general applications provide `send()` and `createHttpBrowser()`. Use `HttpRequest` to send normal HTTP headers, arbitrary methods and request bodies:

```php
use Contao\E2eTesting\Http\HttpRequest;
use Contao\E2eTesting\Http\Origin;

$request = HttpRequest::json('POST', '/api/example', Origin::http('example.test'))
    ->withHeaders(['Authorization' => 'Bearer e2e'])
    ->withJson(['title' => 'Example']);

$response = self::managedEdition()->send($request);

$this->assertSame(201, $response->getStatusCode());
$this->assertSame('Example', $response->toArray(false)['title']);
```

`HttpRequest::json()` sets `Accept: application/json`. Without `withJson()`, it sends no body and adds no JSON content type. Symfony HttpClient supplies its default form content type for POST requests without a body. `withJson()` encodes the supplied value and defaults both `Accept` and `Content-Type` to `application/json`, preserving explicitly supplied media types such as `application/ld+json`. Passing `null` to `withJson()` sends the JSON value `null`. Omit that call when there is no body.

For other content, use `HttpRequest::create($method, $path, $origin)->withHeaders($headers)->withBody($content)`. `HttpRequest::get($path, $origin)` remains available. Header replacement is case-insensitive and all `with…()` methods return new requests.

Responses use Symfony HttpClient's `ResponseInterface`. Call `toArray(false)` or `getContent(false)` to inspect error responses without throwing for their HTTP status. Redirects are not followed automatically.

## Test HTML without JavaScript

For HTTP tests without JavaScript, use Symfony's BrowserKit client:

```php
$browser = self::managedEdition()->createHttpBrowser(Origin::https('example.test'));
$crawler = $browser->request('GET', '/');

$this->assertSame(200, $browser->getInternalResponse()->getStatusCode());
$this->assertSame('Example', trim($crawler->filterXPath('//head/title')->text()));
```

For a general application, use `self::application()` with the same methods. Omit the origin to use the configured application URL. Origin emulation is also available with `LocalApplicationConfig::php()` and its generated router. Existing servers, custom routers and custom startup commands must handle domains and HTTPS through their own infrastructure.
