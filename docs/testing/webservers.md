# Local application servers

Use `LocalApplicationConfig` when the tests should start and stop a server for your application. It implements the same PHPUnit configuration contract as `ApplicationConfig`, which connects to a server already running at a URL.

The server starts once per test class on a free loopback port. Browser resets between tests leave it running. Releasing the application stops it, including when a test fails. Application installation, builds, migrations and database resets remain your project's responsibility.

Managed Editions use the same server launcher, port allocation, readiness checks, PHP front-controller routing and shutdown. Their configuration also supplies the Contao database environment and frontend origin mapping.

## Serve a PHP project

In the [application guide](../guides/web-application.md), import `LocalApplicationConfig` at the top of the test file and return this configuration from `createApplicationConfig()`:

```php
use Contao\E2eTesting\Application\LocalApplicationConfig;

$config = LocalApplicationConfig::php(dirname(__DIR__, 2));
```

Use the project directory, not its `public/` directory. PHP runs in the project root and serves `public/` by default. Existing files are served directly, while other paths go to `public/index.php` when it exists. This also handles routes containing a dot, such as `/api/data.json`.

Choose another document root or a custom PHP router when needed:

```php
$config = LocalApplicationConfig::php($projectRoot, documentRoot: 'web');
$config = LocalApplicationConfig::php($projectRoot, router: 'tests/router.php');
```

Relative document roots and router paths are resolved against the project directory. A custom router follows PHP's built-in server contract, returning `false` when PHP should serve a file directly. The tooling does not modify your project or remove a supplied router.

## Run another application's server command

Provide an argument list and a working directory:

```php
$config = LocalApplicationConfig::command(
    ['node', 'server.js', '{port}'],
    $projectRoot,
);
```

This example assumes your `server.js` reads its port from the first command-line argument. Adapt the arguments to your application's normal startup command. `{port}` is replaced with the allocated port wherever it appears in an argument. Commands run directly, without a shell. Shell syntax such as pipes and inline variable assignments does not apply.

Use a command that runs the server in the foreground, without detaching it into a background process. It must serve HTTP on `127.0.0.1` at that port. Install its dependencies and build the application first. Startup waits up to 15 seconds for the port to accept connections. This checks that the server is listening, rather than running an application-specific health check.

Configure your application to write logs to a file if you need them for debugging. Server stdout and stderr are discarded so logging cannot block HTTP requests.

## Pass application settings

The server inherits the test process's environment. Supply overrides through a cloned configuration:

```php
$config = $config->withEnvironment([
    'APP_ENV' => 'test',
    'DATABASE_URL' => 'mysql://user:password@127.0.0.1:3306/application_test',
]);
```

Choose variables appropriate to your application. These values configure the server process and do not provision or reset a database. Use `false` as a value to remove an inherited variable. `withTraceDirectory()` chooses where browser traces are written.

## Connect to an existing server

Continue using `ApplicationConfig::create($url)` for remote environments, HTTPS, containers managed elsewhere or servers that are already running. That configuration does not launch or stop a process. Both configurations use the same browser and Contao backend helpers.
