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
}
