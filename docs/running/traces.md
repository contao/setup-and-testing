# Debugging with traces

Tracing is meant for debugging failing tests locally and is disabled by default.
Set `CONTAO_E2E_TRACE` to record a [Playwright trace](https://playwright.dev/docs/trace-viewer) for every browser
session. A trace contains a DOM snapshot and screenshot for every action as well as network requests, console messages
and sources, so a failure can be inspected step by step after the run:

| Value        | Behavior                                  |
|--------------|-------------------------------------------|
| `on-failure` | Record every test, keep failed tests only |
| `always`     | Record and keep every test                |

Enable tracing for a run:

```shell
CONTAO_E2E_TRACE=on-failure vendor/bin/phpunit --testsuite=e2e
```

PowerShell:

```powershell
$env:CONTAO_E2E_TRACE = 'on-failure'
vendor/bin/phpunit --testsuite=e2e
```

To keep tracing enabled locally, add this inside the `<phpunit>` element in `phpunit.xml`, merging it into an existing `<php>` element if needed:

```xml
<php>
  <env name="CONTAO_E2E_TRACE" value="on-failure"/>
</php>
```

PowerShell settings persist in the terminal. Run `Remove-Item Env:CONTAO_E2E_TRACE` to disable the shell setting afterward.

After a failure, copy the ready-to-run trace command printed by PHPUnit. It quotes the actual trace path, including spaces. To open a trace yourself, replace the quoted example path below with your trace file:

```shell
npx playwright show-trace ".contao-e2e/traces/your-trace.zip"
```

The path of each written trace is printed in the CLI. The Playwright CLI is installed together with the browsers by
`vendor/bin/playwright-install`. You can alternatively drop the file on [trace.playwright.dev](https://trace.playwright.dev), which will open
it locally in the browser.

Recording slows down every test, so leave `CONTAO_E2E_TRACE` unset on CI and only enable it for a local run.

## Choose the trace directory

URL-based and local-server tests write to `.contao-e2e/traces` relative to the PHPUnit working directory. To use another directory, add `withTraceDirectory()` to the configuration returned by `createApplicationConfig()`:

```php
use Contao\E2eTesting\Application\ApplicationConfig;

$config = ApplicationConfig::create('http://localhost:8080')
    ->withTraceDirectory(dirname(__DIR__, 2).'/.contao-e2e/traces');
```

Import `ApplicationConfig` at the top of the file. Adjust the path for your test directory. `withTraceDirectory()` returns a new configuration. `LocalApplicationConfig` provides the same method. Managed Editions store traces below their configured E2E workspace.
