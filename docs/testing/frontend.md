# Frontend tests and HTTP requests

This page covers frontend testing in a Managed Edition. For an existing project, use its URL as shown in [the application guide](../guides/web-application.md). The same isolated Contao installation can serve frontend and backend tests. Prepare the page structure and content through recipe fixtures, and add project files such as templates and assets through recipe file mappings. Then visit a frontend URL with the generic browser:

```php
$browser = self::managedEdition()->createBrowser();
$browser->visit('/');

$this->assertSelectorTextContains('h1', 'Welcome');
```

## Test HTTP and JSON endpoints

Both Managed Editions and general applications provide `send()` and `createHttpBrowser()`. Use `HttpRequest` to send normal HTTP headers, arbitrary methods and request bodies:

```php
use Contao\E2eTesting\Http\HttpRequest;

$request = HttpRequest::json('POST', '/api/example')
    ->withHeaders(['Authorization' => 'Bearer e2e'])
    ->withJson(['title' => 'Example']);

$response = self::managedEdition()->send($request);

$this->assertSame(201, $response->getStatusCode());
$this->assertSame('Example', $response->toArray(false)['title']);
```

`HttpRequest::json()` sets `Accept: application/json`. Without `withJson()`, it sends no body and adds no JSON content type. Symfony HttpClient supplies its default form content type for POST requests without a body. `withJson()` encodes the supplied value and defaults both `Accept` and `Content-Type` to `application/json`, preserving explicitly supplied media types such as `application/ld+json`. Passing `null` to `withJson()` sends the JSON value `null`. Omit that call when there is no body.

For other content, use `HttpRequest::create($method, $path)->withHeaders($headers)->withBody($content)`. `HttpRequest::get($path)` remains available. Header replacement is case-insensitive and all `with…()` methods return new requests.

Responses use Symfony HttpClient's `ResponseInterface`. Call `toArray(false)` or `getContent(false)` to inspect error responses without throwing for their HTTP status. Redirects are not followed automatically.

## Test HTML without JavaScript

For HTTP tests without JavaScript, use Symfony's BrowserKit client:

```php
$browser = self::managedEdition()->createHttpBrowser();
$crawler = $browser->request('GET', '/');

$this->assertSame(200, $browser->getInternalResponse()->getStatusCode());
$this->assertSame('Example', trim($crawler->filterXPath('//head/title')->text()));
```

For a general application, use `self::application()` with the same methods. All requests use the configured application URL.

## Test a page domain

For HTTP tests that need a particular Contao page domain, send the `Host` header:

```php
$response = self::managedEdition()->send(
    HttpRequest::get('/')->withHeader('Host', 'example.test'),
);
```

This changes the hostname received by the application while the connection still uses the local server URL. The HTTP `Origin` header can also be supplied through `withHeader()` when testing cross-origin requests.

For BrowserKit, use an absolute request URL when overriding `Host` so BrowserKit keeps the connection pointed at the local server:

```php
$application = self::managedEdition();
$browser = $application->createHttpBrowser();
$browser->request('GET', $application->uri('/'), server: ['HTTP_HOST' => 'example.test']);
```

Playwright uses the application's actual URL for navigation, cookies and redirects. To test a particular domain or HTTPS in a real browser, configure your test server accordingly and connect through `ApplicationConfig::create($url)`.
