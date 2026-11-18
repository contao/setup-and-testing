<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Mail;

use Contao\E2eTesting\Exception\MailCaptureException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\After;

trait MailAssertionsTrait
{
    private Mailbox|null $assertionMailbox = null;

    #[After]
    protected function resetAssertedMailbox(): void
    {
        $this->assertionMailbox?->reset();
        $this->assertionMailbox = null;
    }

    abstract protected function mailbox(): Mailbox;

    protected function assertEmailSent(MessageQuery $query, float $timeout = 5): CapturedMessage
    {
        try {
            $message = $this->mailboxForAssertions()->waitForMessage($query, $timeout);
            Assert::assertNotEmpty($message->id(), 'The captured email must have a message ID.');

            return $message;
        } catch (MailCaptureException $exception) {
            Assert::fail($exception->getMessage());
        }
    }

    protected function assertEmailCount(int $expected, MessageQuery $query): void
    {
        Assert::assertCount($expected, $this->mailboxForAssertions()->messages($query), 'Unexpected email count for '.$query->describe());
    }

    protected function assertEmailTextContains(string $expected, CapturedMessage $message): void
    {
        Assert::assertStringContainsString($expected, $message->text());
    }

    protected function assertEmailHtmlContains(string $expected, CapturedMessage $message): void
    {
        Assert::assertStringContainsString($expected, $message->html());
    }

    protected function assertEmailHasAttachment(string $filename, CapturedMessage $message): void
    {
        Assert::assertContains($filename, $message->attachmentNames());
    }

    private function mailboxForAssertions(): Mailbox
    {
        return $this->assertionMailbox ??= $this->mailbox();
    }
}
