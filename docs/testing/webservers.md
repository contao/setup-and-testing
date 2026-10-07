# Local application servers

Use `LocalApplicationConfig` when the tests should start and stop a server for your application. It implements the same PHPUnit configuration contract as `ApplicationConfig`, which connects to a server already running at a URL.

The server starts once per test class on a free loopback port. Browser resets between tests leave it running. Releasing the application stops it, including when a test fails. Application installation, builds, migrations and database resets remain your project's responsibility.

Managed Editions use the same server launcher, port allocation, readiness checks, PHP front-controller routing and shutdown. Their configuration also supplies the Contao database environment.

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

## Configure the spawned PHP process

PHP servers start as separate processes. They read the usual PHP configuration files, but do not inherit the test process's `ini_set()` changes or PHP `-d` arguments. Use `PhpServerConfig` to pass settings directly to the spawned server:

```php
use Contao\E2eTesting\Http\PhpServerConfig;

$php = (new PhpServerConfig())
    ->withOpcache()
    ->withIniSettings([
        'memory_limit' => '256M',
        'max_execution_time' => 60,
        'display_errors' => false,
    ]);

$config = LocalApplicationConfig::php($projectRoot)->withPhpServer($php);
```

Managed Editions enable the OPcache preset by default and apply the cache sizes recommended in [Contao's PHP setup guide](https://docs.contao.org/5.x/manual/en/performance/php-setup/):

| Directive | Default |
| --- | --- |
| `opcache.memory_consumption` | `128` MB |
| `opcache.max_accelerated_files` | `20000` |
| `opcache.interned_strings_buffer` | `32` MB |
| `realpath_cache_size` | `4096K` |
| `realpath_cache_ttl` | `600` seconds |

The production guide also recommends disabling OPcache timestamp validation. The testing defaults keep it enabled on every request because tests can change PHP files while the server is running. The server continues to use PHP's built-in SAPI rather than the production guide's PHP-FPM recommendation.

To customize server settings while retaining these defaults, derive the PHP configuration from the edition:

```php
$config = $config->withPhpServer(
    $config->phpServer()->withIniSettings(['memory_limit' => '256M'])
);

// Disable OPcache for this edition.
$config = $config->withPhpServer($config->phpServer()->withOpcache(false));
```

The configuration methods return clones. `withIniSettings()` merges directive values with existing settings, replacing only matching names. `withPhpServer()` replaces the complete PHP configuration. Values may be strings, integers or booleans. Settings are passed as PHP `-d` arguments before the server options, with strings quoted for PHP's INI parser. They apply to HTTP serving, while Composer, migrations and other console commands retain their own PHP settings. Local PHP servers add no tuning overrides by default.

`withOpcache()` enables both `opcache.enable` and `opcache.enable_cli`. The OPcache extension must already be installed and loaded by the server's PHP binary. Without the extension, the server still runs, but does not benefit from caching. The preset enables timestamp validation on every request and disables inherited disk caching. Additional `withIniSettings()` calls can override these values. Use `withOpcache(false)` to disable OPcache explicitly.

The server keeps its in-memory OPcache across requests and inter-test application resets. Releasing the application stops its server. Managed Editions each own a server process, so cached bytecode stays within that server's lifetime. On Windows, the launcher supplies a fresh `opcache.cache_id` for each PHP server, overriding any configured cache ID to prevent shared caches between editions. Persistent disk caching is outside this isolation guarantee if you explicitly enable it.

Keep timestamp validation enabled when tests change or synchronize PHP files. Disabling `opcache.validate_timestamps` requires invalidation from the running server or a server restart for code changes to take effect. Resetting OPcache in PHPUnit's parent process does not clear the server's cache. See [PHP's OPcache configuration](https://www.php.net/manual/en/opcache.configuration.php).

PHP settings apply to servers created with `php()`. For `command()` configurations, supply your runtime's options directly in the command arguments.

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
