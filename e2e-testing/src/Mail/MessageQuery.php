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

final class MessageQuery
{
    private string|null $subject = null;

    private function __construct(public readonly string $recipient)
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || false !== strpbrk($recipient, "\"\\\r\n ")) {
            throw new \InvalidArgumentException('Use a plain email address as the message recipient.');
        }
    }

    public static function to(string $recipient): self
    {
        return new self($recipient);
    }

    public static function uniqueRecipient(): string
    {
        return 'test-'.bin2hex(random_bytes(16)).'@example.test';
    }

    public function withSubject(string $subject): self
    {
        $clone = clone $this;
        $clone->subject = $subject;

        return $clone;
    }

    public function search(): string
    {
        return 'to:"'.$this->recipient.'"';
    }

    public function matches(CapturedMessage $message): bool
    {
        return \in_array(strtolower($this->recipient), array_map(strtolower(...), $message->to()), true)
            && (null === $this->subject || $this->subject === $message->subject());
    }

    public function describe(): string
    {
        return 'recipient '.$this->recipient.(null === $this->subject ? '' : ', subject "'.$this->subject.'"');
    }
}
