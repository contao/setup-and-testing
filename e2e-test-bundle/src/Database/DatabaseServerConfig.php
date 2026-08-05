<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTestBundle\Database;

use Contao\E2eTestBundle\Exception\E2eTestException;

final readonly class DatabaseServerConfig
{
    public function __construct(public string $url)
    {
        if (!str_starts_with($url, 'mysql://') && !str_starts_with($url, 'mysqli://') && !str_starts_with($url, 'pdo-mysql://')) {
            throw new E2eTestException('The E2E database URL must use MySQL or MySQLi.');
        }
    }

    public static function fromEnvironment(): self
    {
        $config = self::tryFromEnvironment();

        if (!$config) {
            throw new E2eTestException('Set CONTAO_E2E_DATABASE_URL to a MySQL/MariaDB URL that may create test databases.');
        }

        return $config;
    }

    public static function tryFromEnvironment(): self|null
    {
        $url = getenv('CONTAO_E2E_DATABASE_URL');

        return false === $url || '' === $url ? null : new self($url);
    }
}
