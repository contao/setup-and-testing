<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Tests;

use Contao\E2eTesting\Mail\MailAssertionsTrait;
use Contao\E2eTesting\Mail\Mailbox;
use Contao\E2eTesting\Mail\MailCaptureConfig;
use Contao\E2eTesting\Mail\MessageQuery;
use PHPUnit\Exception;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MailAssertionsTraitTest extends TestCase
{
    use MailAssertionsTrait;

    private Mailbox|null $configuredMailbox = null;

    private int $mailboxCreations = 0;

    public function testCleanupDoesNotCreateAnUnusedMailbox(): void
    {
        $this->resetAssertedMailbox();
        $this->resetAssertedMailbox();

        $this->assertSame(0, $this->mailboxCreations);
    }

    public function testMailAssertionsReuseOneMailboxAndCleanupOnlyOnce(): void
    {
        $requests = [];
        $client = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = [$method, $options['body'] ?? null];

                if ('DELETE' === $method) {
                    return new MockResponse('OK');
                }

                return new MockResponse(str_contains($url, '/search?')
                    ? '{"messages":[{"ID":"delivered"}],"messages_count":1}'
                    : '{"ID":"delivered","Subject":"Confirm","Text":"Body","HTML":"","To":[{"Address":"test@example.test"}]}',
                );
            },
        );
        $this->configuredMailbox = new Mailbox(new MailCaptureConfig('http://mail-capture', 'smtp://localhost'), $client);
        $query = MessageQuery::to('test@example.test');
        $this->assertEmailSent($query);
        $this->assertEmailCount(1, $query);
        $this->resetAssertedMailbox();
        $this->resetAssertedMailbox();

        $this->assertSame(1, $this->mailboxCreations);
        $this->assertCount(5, $requests);
        $this->assertSame(['DELETE', '{"IDs":["delivered"]}'], $requests[4]);
    }

    public function testSuccessfulDeliveryCountsAsAPhpUnitAssertion(): void
    {
        $this->configuredMailbox = new Mailbox(
            new MailCaptureConfig('http://mail-capture', 'smtp://localhost'),
            new MockHttpClient([
                new MockResponse('{"messages":[{"ID":"delivered"}],"messages_count":1}'),
                new MockResponse('{"ID":"delivered","Subject":"Confirm","Text":"Body","HTML":"","To":[{"Address":"test@example.test"}]}'),
                new MockResponse('OK'),
            ]),
        );
        $before = $this->getCount();
        $message = $this->assertEmailSent(MessageQuery::to('test@example.test'));

        $this->assertSame($before + 1, $this->getCount());
        $this->assertSame('delivered', $message->id());
    }

    public function testMissingMailProducesAPhpUnitAssertionFailure(): void
    {
        try {
            $this->assertEmailSent(MessageQuery::to('test@example.test'), 0.001);
        } catch (Exception $exception) {
            $this->assertStringContainsString('No email arrived', $exception->getMessage());

            return;
        }

        $this->fail('Missing mail should fail the PHPUnit assertion.');
    }

    public function testCanAssertAnEmptyMailbox(): void
    {
        $this->assertEmailCount(0, MessageQuery::to('test@example.test'));
    }

    protected function mailbox(): Mailbox
    {
        ++$this->mailboxCreations;

        return $this->configuredMailbox ?? new Mailbox(
            new MailCaptureConfig('http://mail-capture', 'smtp://localhost'),
            new MockHttpClient(static fn () => new MockResponse('{"messages":[],"messages_count":0}')),
        );
    }
}
