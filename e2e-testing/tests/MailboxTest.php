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

use Contao\E2eTesting\Exception\MailCaptureException;
use Contao\E2eTesting\Mail\CapturedMessage;
use Contao\E2eTesting\Mail\Mailbox;
use Contao\E2eTesting\Mail\MailCaptureConfig;
use Contao\E2eTesting\Mail\MessageQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MailboxTest extends TestCase
{
    public function testResetDoesNotSendRequestsForAnUntouchedOrEmptyMailbox(): void
    {
        $client = new MockHttpClient([new MockResponse('{"messages":[],"messages_count":0}')]);
        $mailbox = new Mailbox(new MailCaptureConfig('http://mail-capture', 'smtp://localhost'), $client);
        $mailbox->reset();
        $mailbox->reset();
        $this->assertSame(0, $client->getRequestsCount());
        $this->assertSame([], $mailbox->messages(MessageQuery::to('test@example.test')));
        $mailbox->reset();
        $this->assertSame(1, $client->getRequestsCount());
    }

    public function testResetBatchesMatchingMessagesWithoutDeletingOtherMail(): void
    {
        $requests = [];
        $responses = [
            ['messages' => [['ID' => 'one'], ['ID' => 'unrelated'], ['ID' => 'two']], 'messages_count' => 3],
            $this->message('one'),
            $this->message('unrelated', 'other-test@example.test'),
            $this->message('two'),
        ];
        $client = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$responses, &$requests): MockResponse {
                $requests[] = [$method, $url, $options['body'] ?? null];

                return new MockResponse('DELETE' === $method ? 'OK' : json_encode(array_shift($responses), JSON_THROW_ON_ERROR));
            },
        );
        $mailbox = new Mailbox(new MailCaptureConfig('http://mail-capture', 'smtp://localhost'), $client);
        $this->assertCount(2, $mailbox->messages(MessageQuery::to('test@example.test')));
        $mailbox->reset();
        $mailbox->reset();

        $this->assertCount(5, $requests);
        $this->assertSame(['DELETE', 'http://mail-capture/api/v1/messages', '{"IDs":["one","two"]}'], $requests[4]);
    }

    public function testFailedResetCanBeRetriedWithoutLosingPendingMessages(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"messages":[{"ID":"pending"}],"messages_count":1}'),
            new MockResponse(json_encode($this->message('pending'), JSON_THROW_ON_ERROR)),
            new MockResponse('Unavailable', ['http_code' => 503]),
            new MockResponse('OK'),
        ]);
        $mailbox = new Mailbox(new MailCaptureConfig('http://mail-capture', 'smtp://localhost'), $client);
        $mailbox->waitForMessage(MessageQuery::to('test@example.test'));

        try {
            $mailbox->reset();
            $this->fail('Expected the failed deletion to be reported.');
        } catch (MailCaptureException $exception) {
            $this->assertStringContainsString('Could not reset captured mail', $exception->getMessage());
        }

        $mailbox->reset();
        $mailbox->reset();
        $this->assertSame(4, $client->getRequestsCount());
    }

    public function testFiltersExactRecipientAndSubjectAndFollowsPagination(): void
    {
        $urls = [];
        $responses = [
            ['messages' => [['ID' => 'partial'], ['ID' => 'wrong-subject']], 'messages_count' => 3],
            $this->message('partial', 'other-test@example.test'),
            $this->message('wrong-subject', 'test@example.test', 'Confirm now'),
            ['messages' => [['ID' => 'match']], 'messages_count' => 3],
            $this->message('match', 'TEST@example.test'),
        ];
        $client = new MockHttpClient(
            static function (string $method, string $url) use (&$responses, &$urls): MockResponse {
                $urls[] = $url;

                return new MockResponse(json_encode(array_shift($responses), JSON_THROW_ON_ERROR));
            },
        );
        $mailbox = new Mailbox(new MailCaptureConfig('http://mail-capture/', 'smtp://localhost:1025'), $client);
        $query = MessageQuery::to('test@example.test')->withSubject('Confirm');
        $messages = $mailbox->messages($query);

        $this->assertCount(1, $messages);
        $this->assertSame('match', $messages[0]->id());
        $this->assertSame('http://mail-capture/api/v1/search?query=to%3A%22test%40example.test%22&start=0&limit=100', $urls[0]);
        $this->assertStringContainsString('start=2', $urls[3]);
        $this->assertSame([], $responses);
    }

    public function testPollsUntilAMessageArrives(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"messages":[],"messages_count":0}'),
            new MockResponse('{"messages":[{"ID":"arrived"}],"messages_count":1}'),
            new MockResponse(json_encode($this->message('arrived'), JSON_THROW_ON_ERROR)),
        ]);
        $mailbox = new Mailbox(new MailCaptureConfig('http://mail-capture', 'smtp://localhost'), $client);

        $this->assertSame('arrived', $mailbox->waitForMessage(MessageQuery::to('test@example.test'))->id());
        $this->assertSame(3, $client->getRequestsCount());
    }

    public function testWaitReturnsTheFirstMatchWithoutReadingOlderMail(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"messages":[{"ID":"latest"},{"ID":"older"}],"messages_count":101}'),
            new MockResponse(json_encode($this->message('latest'), JSON_THROW_ON_ERROR)),
        ]);
        $mailbox = new Mailbox(new MailCaptureConfig('http://mail-capture', 'smtp://localhost'), $client);

        $this->assertSame('latest', $mailbox->waitForMessage(MessageQuery::to('test@example.test'))->id());
        $this->assertSame(2, $client->getRequestsCount());
    }

    public function testTimeoutReportsRecipientSubjectAndInbox(): void
    {
        $client = new MockHttpClient(static fn () => new MockResponse('{"messages":[],"messages_count":0}'));
        $mailbox = new Mailbox(new MailCaptureConfig('http://mail-capture', 'smtp://localhost'), $client);
        $this->expectException(MailCaptureException::class);
        $this->expectExceptionMessage('No email arrived for recipient test@example.test, subject "Confirm" within 0.01 seconds. Mail capture API: http://mail-capture');

        $mailbox->waitForMessage(MessageQuery::to('test@example.test')->withSubject('Confirm'), 0.01);
    }

    public function testApiErrorsAreNotReportedAsAnEmptyInbox(): void
    {
        $client = new MockHttpClient([new MockResponse('unavailable', ['http_code' => 503])]);
        $mailbox = new Mailbox(new MailCaptureConfig('http://mail-capture', 'smtp://localhost'), $client);
        $this->expectException(MailCaptureException::class);
        $this->expectExceptionMessage('Could not read captured mail');

        $mailbox->messages(MessageQuery::to('test@example.test'));
    }

    public function testRejectsMalformedSearchResponse(): void
    {
        $client = new MockHttpClient([new MockResponse('{"messages":null}')]);
        $mailbox = new Mailbox(new MailCaptureConfig('http://mail-capture', 'smtp://localhost'), $client);
        $this->expectException(MailCaptureException::class);

        $mailbox->messages(MessageQuery::to('test@example.test'));
    }

    public function testRejectsInvalidTimeout(): void
    {
        $mailbox = new Mailbox(new MailCaptureConfig('http://mail-capture', 'smtp://localhost'), new MockHttpClient());
        $this->expectException(\InvalidArgumentException::class);

        $mailbox->waitForMessage(MessageQuery::to('test@example.test'), 0);
    }

    public function testQueryIsImmutableAndRecipientCannotInjectSearchFilters(): void
    {
        $query = MessageQuery::to('test@example.test');
        $withSubject = $query->withSubject('Different');
        $message = new CapturedMessage($this->message('id'));

        $this->assertTrue($query->matches($message));
        $this->assertFalse($withSubject->matches($message));
        $this->assertNotSame(MessageQuery::uniqueRecipient(), MessageQuery::uniqueRecipient());
        $this->expectException(\InvalidArgumentException::class);

        MessageQuery::to('test@example.test" subject:other');
    }

    public function testExposesMessageBodiesAddressesAndAttachmentNames(): void
    {
        $data = $this->message('id');
        $data['Attachments'] = [['FileName' => 'invoice.pdf']];
        $data['Cc'] = [['Address' => 'copy@example.test']];
        $data['Bcc'] = null;
        $message = new CapturedMessage($data);

        $this->assertSame('test@example.test', $message->to()[0]);
        $this->assertSame('sender@example.test', $message->from());
        $this->assertSame(['copy@example.test'], $message->cc());
        $this->assertSame([], $message->bcc());
        $this->assertSame('Confirm', $message->subject());
        $this->assertSame('Confirm your account', $message->text());
        $this->assertSame('<p>Confirm your account</p>', $message->html());
        $this->assertSame(['invoice.pdf'], $message->attachmentNames());
    }

    /**
     * @return array<string, mixed>
     */
    private function message(string $id, string $to = 'test@example.test', string $subject = 'Confirm'): array
    {
        return [
            'ID' => $id, 'Subject' => $subject, 'To' => [['Address' => $to]],
            'From' => ['Address' => 'sender@example.test'],
            'Text' => 'Confirm your account', 'HTML' => '<p>Confirm your account</p>',
        ];
    }
}
