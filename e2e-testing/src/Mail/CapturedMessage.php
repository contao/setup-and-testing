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

final readonly class CapturedMessage
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data)
    {
        foreach (['ID', 'Subject', 'Text', 'HTML'] as $field) {
            $this->string($field);
        }
    }

    public function id(): string
    {
        return $this->string('ID');
    }

    public function subject(): string
    {
        return $this->string('Subject');
    }

    public function text(): string
    {
        return $this->string('Text');
    }

    public function html(): string
    {
        return $this->string('HTML');
    }

    public function from(): string
    {
        $from = $this->data['From'] ?? null;

        if (!\is_array($from) || !\is_string($from['Address'] ?? null)) {
            throw new MailCaptureException('Mail capture returned an invalid sender address.');
        }

        return $from['Address'];
    }

    /**
     * @return list<string>
     */
    public function to(): array
    {
        return $this->addresses('To');
    }

    /**
     * @return list<string>
     */
    public function cc(): array
    {
        return $this->addresses('Cc');
    }

    /**
     * @return list<string>
     */
    public function bcc(): array
    {
        return $this->addresses('Bcc');
    }

    /**
     * @return list<string>
     */
    public function attachmentNames(): array
    {
        $attachments = $this->data['Attachments'] ?? [];

        if (!\is_array($attachments)) {
            throw new MailCaptureException('Mail capture returned invalid attachments.');
        }

        $names = [];

        foreach ($attachments as $attachment) {
            if (!\is_array($attachment) || !\is_string($attachment['FileName'] ?? null)) {
                throw new MailCaptureException('Mail capture returned an invalid attachment filename.');
            }

            $names[] = $attachment['FileName'];
        }

        return $names;
    }

    private function string(string $field): string
    {
        $value = $this->data[$field] ?? null;

        if (!\is_string($value)) {
            throw new MailCaptureException('Mail capture returned an invalid message field: '.$field);
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function addresses(string $field): array
    {
        $addresses = $this->data[$field] ?? [];

        if (!\is_array($addresses)) {
            throw new MailCaptureException('Mail capture returned invalid message addresses: '.$field);
        }

        $result = [];

        foreach ($addresses as $address) {
            if (!\is_array($address) || !\is_string($address['Address'] ?? null)) {
                throw new MailCaptureException('Mail capture returned an invalid message address: '.$field);
            }

            $result[] = $address['Address'];
        }

        return $result;
    }
}
