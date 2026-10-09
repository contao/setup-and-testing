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

## Record videos

Set `PW_VIDEOS_DIR` to record every page in each browser context, in headed or headless runs. Recording is disabled
when this variable is unset or empty. These options are supported by `contao/e2e-testing`:

| Option | Default | Behavior |
| --- | --- | --- |
| `PW_VIDEOS_DIR` | Unset, recording disabled | Output directory, preferably an absolute path |
| `PW_HEADLESS` | `true` | `false` opens a visible browser, independently of recording |
| `PW_SLOWMO_MS` | `0` | Delay browser operations by this many milliseconds, independently of recording |
| `PW_VIDEO_WIDTH`, `PW_VIDEO_HEIGHT` | Unset | Paired positive integer video dimensions in pixels, interpreted by Contao |
| `BrowserOptions::withViewport($width, $height)` | Playwright's `1280 × 720` viewport | Positive integer page viewport dimensions |
| `BrowserOptions::withVideoSize($width, $height)` | Effective initial viewport | Positive integer video dimensions, overriding the environment pair |

To record one test while watching slower browser actions, replace the filter with your test method's name:

```shell
PW_VIDEOS_DIR="$PWD/.contao-e2e/videos" PW_HEADLESS=false PW_SLOWMO_MS=250 \
  vendor/bin/phpunit --filter=testBackendLogin
```

Use an absolute output path as above. Relative paths are resolved against the Playwright PHP server working directory,
which in version 1.5.0 is normally `vendor/playwright-php/playwright/bin`, rather than the PHPUnit working directory.
Playwright creates the output directory and writes uniquely named `.webm` files, one per page. Share the WebM file
with a player that supports WebM. Files are finalized when the browser context closes, normally during test teardown.
If you manage sessions yourself, call `$browser->close()` before reading or sharing the completed recording.
Recordings show page content, including scrolling and interactions, rather than browser chrome, address bars or tabs.
They capture the viewport, not the entire scrollable document at once.

### Viewport and video dimensions

By default, video dimensions match the initial viewport, without Playwright's usual scaling down to fit within
`800 × 800`. Browser limitations affecting edge capture are described below. Configure the viewport and optionally
choose a separate video size:

```php
use Contao\E2eTesting\Browser\BrowserOptions;
use Contao\E2eTesting\Browser\BrowserType;

$options = BrowserOptions::create()
    ->withViewport(1440, 1000)
    ->withVideoSize(1440, 1000);

$browser = self::application()->createBrowser(BrowserType::Chromium, $options);
```

Omit `withVideoSize()` to match the viewport automatically. Every `with…()` method returns a clone. The configured
video values are available through `videoWidth()` and `videoHeight()`.

To choose video dimensions without changing a test:

```shell
PW_VIDEOS_DIR="$PWD/.contao-e2e/videos" PW_VIDEO_WIDTH=1280 PW_VIDEO_HEIGHT=720 \
  vendor/bin/phpunit --filter=testBackendLogin
```

Video dimension precedence, highest first:

1. Explicit `BrowserOptions::withVideoSize()` values for that browser session.
2. `PW_VIDEO_WIDTH` and `PW_VIDEO_HEIGHT`, which must both be present and valid.
3. Explicit `BrowserOptions::withViewport()` values.
4. Playwright's default viewport of `1280 × 720`.

Video dimensions do not change the viewport or enable recording. With recording disabled, they are ignored during
normalization, including invalid environment values. `withVideoSize()` always validates its arguments. With recording
enabled, invalid or incomplete environment pairs throw `InvalidArgumentException` before browser launch, unless an
explicit video size overrides them. Empty environment values are treated as unset. Dimensions must be decimal
positive integers representable by PHP, without signs, whitespace or fractions.

### Resizing and browser limitations

Video frame dimensions are fixed when the context is created. Set the viewport before creating a recording.
Changing it later with `$browser->page()->setViewportSize($width, $height)` does not resize the video frame.

Visual verification on macOS Retina with Playwright PHP 1.5.0 and Node Playwright 1.63.0 confirmed full right and
bottom edges at `1440 × 1000` in headed Chromium and WebKit, and all three headless browsers. Enlarging the page
viewport to `1600 × 1100` kept the video at `1440 × 1000`. Chromium and WebKit scaled the content to fit. Physically shrinking
Chromium's browser window to `1000 × 700` left the emulated viewport at `1440 × 1000`, but clipped the captured
content. Keep the native window large enough and avoid resizing it during a recording.

Headed Firefox on this Retina display cropped the top-left portion even with matching dimensions. The issue also
reproduced directly in Node Playwright, independently of PHP and Contao, with `deviceScaleFactor: 1`. A separate
Node experiment setting Firefox's `layout.css.devPixelsPerPx` preference to `1.0` restored the complete frame.
Playwright PHP 1.5.0 does not expose `firefoxUserPrefs` in its launch builder. Use Chromium or WebKit for headed
recordings on affected displays, or headless Firefox. The dimensions API needs no upstream PHP changes. Exposing
Firefox launch preferences upstream would make the verified workaround available without modifying vendor files.
These observations are specific to the versions and platform tested, not a guarantee for other browser builds.
