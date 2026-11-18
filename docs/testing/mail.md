# Test email delivery

Enable mail capture to inspect emails sent over SMTP through an HTTP API. The application uses its real mail transport and renders its real messages. Delivery to the catcher proves SMTP delivery to that service, rather than delivery to an external inbox. Mailpit currently provides the Docker service and API implementation. Tests use service-neutral configuration and assertion helpers.

## Enable capture in a Managed Edition

Opt in through your application's configuration:

```php
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;

return ManagedEditionConfig::create($recipe, $projectDirectory)
    ->withMailCapture();
```

This starts a Mailpit Docker container when needed and sets `MAILER_DSN` for the installation commands, subsequent console commands and HTTP server. The generated DSN takes precedence over a `MAILER_DSN` supplied through `withEnvironmentVariables()`. The parent PHP process environment stays unchanged. Your application's mailer configuration must use `MAILER_DSN`.

Mailpit uses dynamically assigned SMTP and HTTP ports bound to `127.0.0.1`. Each PHP test process owns its container, reuses it for matching project and image configurations, and removes it at normal process exit. No relay is configured. Docker must be available on the machine running the PHP tests. Remote Docker hosts and applications running on other machines or in other containers need an externally configured instance with reachable addresses.

Mail capture participates in the existing [Docker warm-up](databases.md) mechanism. Disable it with `withMailCapture(null)`.

## Use an existing mail catcher

Keep the same `withMailCapture()` call in your tests and configure the connection through the environment, as with `CONTAO_E2E_DATABASE_URL` for an external database:

```shell
export CONTAO_E2E_MAIL_CAPTURE_API_URL=http://mail-catcher.local:8025
export CONTAO_E2E_MAIL_CAPTURE_SMTP_DSN=smtp://mail-catcher.local:1025
composer test
```

Both variables must be set together. With neither set, `withMailCapture()` provisions Docker automatically. External settings do not enable mail capture on their own. An explicit `MailCaptureConfig` or `DockerMailCaptureConfig` takes precedence, and `withMailCapture(null)` still disables capture. Invalid or incomplete environment settings fail instead of silently starting a container.

## Choose the Docker image

The default image uses the floating release tag `axllent/mailpit:latest`, following Docker's normal local image caching behavior.

For a per-class choice, pass `withMailCapture(new DockerMailCaptureConfig('axllent/mailpit:v1.27.4'))` from the `Contao\E2eTesting\Mail` namespace. Image selection participates in container reuse, so different images receive different containers. Custom images must provide the Mailpit-compatible API, SMTP ports and startup options used by the current implementation.

## Assert on captured messages

Managed Edition test cases and `ManagedEditionTestTrait` include the email assertion helpers. They work with browser tests, BrowserKit requests and direct HTTP requests:

```php
use Contao\E2eTesting\Mail\MessageQuery;

public function testRegistrationEmail(): void
{
    $recipient = MessageQuery::uniqueRecipient();
    $query = MessageQuery::to($recipient)->withSubject('Confirm your registration');

    // Submit the registration form using this recipient
    $browser = self::managedEdition()->createHttpBrowser();
    $browser->request('GET', '/register');
    $browser->submitForm('Register', [
        'email' => $recipient,
        'password' => 'test-password',
    ]);

    $message = $this->assertEmailSent($query);
    $this->assertEmailTextContains('Confirm your account', $message);
    $this->assertEmailHtmlContains('Confirm your account', $message);
    $this->assertEmailCount(1, $query);
}
```

Adapt the form URL, field names and subject to your application. `assertEmailSent()` polls for up to five seconds by default. Supply a second argument to change the timeout. Missing mail produces a PHPUnit failure containing the recipient, optional subject and mail capture API URL. API and connection errors are reported explicitly.

`MessageQuery::to()` matches an exact address in the message's `To` header, ignoring ASCII case. `withSubject()` adds an exact, case-sensitive subject match. Search results are paginated automatically. CC and BCC are available on the captured message, but are not matched by `to()`.

A captured message exposes `id()`, `from()`, `to()`, `cc()`, `bcc()`, `subject()`, `text()`, `html()` and `attachmentNames()`. Use `assertEmailHasAttachment('invoice.pdf', $message)` to check for a named attachment. You can parse links from `html()` with Symfony's DOM crawler and follow a confirmation link in your browser test.

## Use the helpers in other functional tests

The client and assertion trait do not depend on a Managed Edition or a browser. Point them at an existing service with a Mailpit-compatible API:

```php
use Contao\E2eTesting\Mail\MailAssertionsTrait;
use Contao\E2eTesting\Mail\Mailbox;
use Contao\E2eTesting\Mail\MailCaptureConfig;
use PHPUnit\Framework\TestCase;

final class RegistrationTest extends TestCase
{
    use MailAssertionsTrait;

    protected function mailbox(): Mailbox
    {
        return new Mailbox(MailCaptureConfig::fromEnvironment());
    }
}
```

Set the two connection variables above and configure the application under test to send to that SMTP DSN before booting its kernel or starting its server. Constructing a `Mailbox` only configures API access. For API authentication, pass a configured Symfony HTTP client as the second constructor argument.

For automatic Docker startup outside Managed Editions, create a `DockerMailCaptureService` with `CacheConfig::forProject($projectDirectory)` and call `connection()`. It returns the `MailCaptureConfig` containing `apiUrl` and `mailerDsn`. A custom PHPUnit base class can also return this service from `DockerServiceProviderInterface::dockerServices()` to participate in warm-up.

Without PHPUnit helpers, use `$mailbox->messages($query)` for a current snapshot or `$mailbox->waitForMessage($query, 10)` for bounded polling. Failed waits throw `MailCaptureException`.

## Isolation and asynchronous mail

Managed Edition test cases and the assertion trait clean up messages observed by the test after it finishes. Cleanup sends one batch deletion only when a query returned matching messages. It makes no empty-inbox probes, and untouched mailboxes or queries returning no matches need no cleanup request. Repeated reads of the same message do not create duplicate deletions. Cleanup also runs after failed tests and reports deletion failures.

Only observed, matching messages are deleted. Other messages in a shared external instance remain untouched. Mail sent without being read by a query, or delivered by a queue after cleanup, is not covered by this tracking. Generate a fresh recipient with `MessageQuery::uniqueRecipient()` for each test to avoid matching those messages or mail from parallel tests.

Managed Edition mailboxes are reused across assertions and cleaned up when the application is released as well. Database resets do not clear the mailbox. If you use the standalone client directly outside the assertion helpers, call `$mailbox->reset()` in your test teardown. It uses the same tracked-message cleanup and does nothing when no matching messages were observed.

Message-count assertions check the current snapshot. A zero count does not prove that queued work will never send mail later. If your application queues mail through Messenger or another queue, run the appropriate worker or configure synchronous handling for the test environment. The mailbox helpers wait for delivery but do not process application queues.

The web UI is available at `$this->mailbox()->config->apiUrl` while the container is running. If PHP is forcibly terminated before shutdown handlers run, remove the abandoned `contao-e2e-mail-capture-*` container manually.

## Run the SMTP integration tests

The regular suite tests the API client and helpers without Docker. Enable the real SMTP and container lifecycle tests explicitly:

```shell
CONTAO_E2E_TEST_MAIL_CAPTURE=1 composer test
```

The shared CI PHPUnit matrix runs the full suite with mail capture enabled on Linux. The Windows job runs the same suite with these Docker integration tests disabled.
