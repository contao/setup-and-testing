# Playwright options

The Playwright configuration can be adjusted using environment variables, so you can change its options without
modifying the tests. The most useful options are:

| Variable        | Default | Behavior                                                     |
|-----------------|---------|--------------------------------------------------------------|
| `PW_TIMEOUT_MS` | `30000` | Timeout for browser actions, waits and navigations           |
| `PW_HEADLESS`   | `true`  | Set to `false` to watch the browser while the tests run      |
| `PW_SLOWMO_MS`  | `0`     | Slows down every browser operation by the given amount       |
| `PW_CHANNEL`    |         | Uses an installed browser channel, e.g. `chrome` or `msedge` |

For example, lower the timeout to let a broken test fail fast while writing it, or raise it on a slow machine:

```shell
PW_TIMEOUT_MS=1000 vendor/bin/phpunit --testsuite=e2e
```

PowerShell:

```powershell
$env:PW_TIMEOUT_MS = '1000'
vendor/bin/phpunit --testsuite=e2e
```

To keep a setting for local runs, add it inside the `<phpunit>` element in your local `phpunit.xml`:

```xml
<php>
  <env name="PW_TIMEOUT_MS" value="1000"/>
</php>
```

Merge it into an existing `<php>` element if there is one. PowerShell environment settings persist in the terminal, as explained in [Windows setup](windows.md#environment-variables). The connection to the Playwright server never uses less than 30 seconds, so launching the browser still works with a low timeout.
