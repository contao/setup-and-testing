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

final readonly class MailCaptureConfig
{
    public function __construct(
        public string $apiUrl,
        public string $mailerDsn,
    ) {
        if (!\in_array(parse_url($apiUrl, PHP_URL_SCHEME), ['http', 'https'], true) || !parse_url($apiUrl, PHP_URL_HOST)) {
            throw new \InvalidArgumentException('The mail capture API URL must be an absolute HTTP or HTTPS URL.');
        }

        if ('smtp' !== parse_url($mailerDsn, PHP_URL_SCHEME) || !parse_url($mailerDsn, PHP_URL_HOST)) {
            throw new \InvalidArgumentException('The mail capture mailer DSN must be an SMTP URL.');
        }
    }

    public static function fromEnvironment(): self
    {
        return self::tryFromEnvironment() ?? throw new MailCaptureException('Set CONTAO_E2E_MAIL_CAPTURE_API_URL and CONTAO_E2E_MAIL_CAPTURE_SMTP_DSN to use an external mail catcher.');
    }

    public static function tryFromEnvironment(): self|null
    {
        $apiUrl = getenv('CONTAO_E2E_MAIL_CAPTURE_API_URL');
        $mailerDsn = getenv('CONTAO_E2E_MAIL_CAPTURE_SMTP_DSN');
        $hasApiUrl = false !== $apiUrl && '' !== $apiUrl;
        $hasMailerDsn = false !== $mailerDsn && '' !== $mailerDsn;

        if (!$hasApiUrl && !$hasMailerDsn) {
            return null;
        }

        if (!$hasApiUrl || !$hasMailerDsn) {
            throw new MailCaptureException('Set both CONTAO_E2E_MAIL_CAPTURE_API_URL and CONTAO_E2E_MAIL_CAPTURE_SMTP_DSN to use an external mail catcher.');
        }

        return new self($apiUrl, $mailerDsn);
    }
}
