# Environment variables

Set environment variables before running PHPUnit or the CLI. See [Windows setup](../running/windows.md) for PowerShell equivalents.

| Variable | Default | Purpose |
| --- | --- | --- |
| `CONTAO_E2E_DIRECTORY` | `.contao-e2e` under the project | Override the E2E workspace |
| `CONTAO_E2E_DATABASE_URL` | Unset | Administrative database server URL, overriding Docker provisioning |
| `CONTAO_E2E_DATABASE_TYPE` | `mariadb` | Docker database type, `mariadb` or `mysql` |
| `CONTAO_E2E_DATABASE_IMAGE` | `mariadb:11.4` or `mysql:8.4`, depending on type | Docker image used for that database type |
| `CONTAO_E2E_NO_CACHE` | Unset | Set to `1` to force a fresh Managed Edition dependency installation |
| `CONTAO_E2E_TRACE` | Unset | `on-failure` or `always`, see [traces](../running/traces.md) |
| `PLAYWRIGHT_BROWSERS_PATH` | Playwright's platform cache | Browser installation and lookup directory, also used by [CI cache metadata](../running/ci.md) |
| `PLAYWRIGHT_NO_REDUCED_MOTION` | `false` | Set through PHP's server environment to use `no-preference` rather than the default reduced motion |
| `PW_TIMEOUT_MS` | `30000` | Browser action, wait and navigation timeout in milliseconds |
| `PW_HEADLESS` | `true` | Set to `false` to watch the browser |
| `PW_SLOWMO_MS` | `0` | Delay browser operations by this many milliseconds |
| `PW_CHANNEL` | Unset | Select an installed browser channel such as `chrome` or `msedge` |

`E2E_BASE_URL` is a convention used by these guides' test configuration, not an environment variable automatically consumed by the library. `DATABASE_URL` in the recipe installer example belongs to the host application's configuration.

PHP configuration methods such as `withDatabase()` provide explicit per-class choices. See [databases](../testing/databases.md) and [Playwright options](../running/playwright.md) for behavior and examples.
