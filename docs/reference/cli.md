# CLI commands

Run commands from the consumer project root. `CONTAO_E2E_DIRECTORY` overrides its workspace. For all available arguments and options:

```shell
vendor/bin/contao-e2e list
vendor/bin/contao-e2e help cache:metadata
```

| Command | Purpose |
| --- | --- |
| `doctor` | Initialize and verify the project-local workspace |
| `cache:metadata` | Prepare cache-key files and print cache fingerprints and roots as JSON |
| `cache:clear` | Clear reusable E2E setup caches |
| `failures:clear` | Clear retained failure artifacts |
| `database:stop` | Stop all Docker database variants belonging to the project |
| `server:start <inspection-file> [-d]` | Prepare a Managed Edition for manual inspection, optionally in the background |
| `server:status` | Show readiness and URLs for the background inspection session |
| `server:stop` | Stop the background inspection session and release its leases |

`cache:metadata` requires the Playwright Node dependencies to have been prepared with `vendor/bin/playwright-install`. It does not install dependencies or access the network itself. See [CI caching](../running/ci.md).

`database:stop` refuses to interrupt active tests unless `--force` is supplied. Cleanup commands act on the project's E2E workspace. See [workspace layout](../testing/caching.md#workspace-and-cleanup).

`server:start` loads a PHP file returning a Managed Edition configuration or a preparation factory. In foreground mode it prints the URLs and waits until you stop the session. With `-d`, preparation continues in a background worker and `server:status` shows readiness. See [manual inspection](../running/inspection.md) for configuration examples and shutdown behavior.

Browser installation is supplied by the Playwright dependency:

```shell
vendor/bin/playwright-install --browsers
```

Use `--with-deps --browsers` on fresh Linux runners to install system dependencies as well as browser binaries.
