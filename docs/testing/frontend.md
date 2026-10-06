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

## Simulate a public origin

Use this for HTTP and BrowserKit tests that depend on domain-based routing, HTTPS detection or generated absolute URLs while the application runs on a local HTTP server.

Enable simulated origins on the managed configuration, usually in your [shared test base class](phpunit.md#enable-simulated-origins-in-a-shared-base-class):

```php
$config = ManagedEditionConfig::create($recipe, $projectRoot)->withSimulatedOrigins();
```

This opt-in adds a bundled Symfony configuration fragment to the isolated installation. It trusts only loopback proxies (`127.0.0.1` and `::1`) and the forwarded host, scheme and port headers. Enabling it refreshes cached application configuration without rebuilding Composer dependencies. It is disabled by default.

Choose the public origin independently for each request:

```php
use Contao\E2eTesting\Http\HttpRequest;

$response = self::managedEdition()->send(
    HttpRequest::json('POST', '/api/example')
        ->withSimulatedOrigin('https://example.local')
        ->withJson(['title' => 'Example']),
);
```

Use the same origin format for a BrowserKit client:

```php
use Contao\E2eTesting\Http\HttpBrowserOptions;

$options = HttpBrowserOptions::create()->withSimulatedOrigin('https://example.local');
$browser = self::managedEdition()->createHttpBrowser($options);
$browser->request('GET', '//');
```

Options are immutable and can be reused across clients. Every `with…()` method returns a clone, so changing an origin leaves existing options and clients unchanged.

The helpers construct `X-Forwarded-Host`, `X-Forwarded-Proto` and `X-Forwarded-Port`. Symfony sees the requested public origin while the connection continues to use the local HTTP server. Origins accept HTTP or HTTPS, a hostname and an optional port, for example `https://example.local:8443`. Paths, credentials, queries and fragments do not belong in the origin. Request paths, including `//`, are preserved.

HTTP requests and BrowserKit leave redirects unfollowed by default, so you can assert the public `Location` URL. Calling BrowserKit's `followRedirect()` maps absolute and protocol-relative URLs for that simulated origin back to the local server and preserves their paths and queries. Forwarded headers are scoped to the configured server and are not sent when the client navigates to another server.

Requests and clients without a simulated origin keep using the ordinary application URL. This simulates the origin seen by Symfony while the connection uses the application’s actual transport. The HTTP `Origin` header remains a normal request header that you can set with `withHeader()`.

Playwright uses the application's actual URL for navigation, cookies and cross-origin rules. Tests that need a particular domain or HTTPS in a real browser should configure their test server accordingly and connect through `ApplicationConfig::create($url)`.

The helpers are also available on existing applications. Those applications must supply their own trusted proxy configuration. The framework never installs configuration into an external application. See [Symfony's trusted proxy configuration](https://symfony.com/doc/current/deployment/proxies.html) for how forwarded headers are interpreted.

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
