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

use Contao\E2eTestBundle\Exception\DockerUnavailableException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

final readonly class DatabaseReadinessProbe
{
    public function wait(DatabaseServerConfig $config): void
    {
        $deadline = microtime(true) + 60;
        $lastException = null;

        do {
            try {
                $connection = DriverManager::getConnection(new DsnParser([
                    'mysql' => 'pdo_mysql',
                    'pdo-mysql' => 'pdo_mysql',
                    'mysqli' => 'mysqli',
                ])->parse($config->url));
                $connection->executeQuery('SELECT 1');
                $connection->close();

                return;
            } catch (\Throwable $exception) {
                $lastException = $exception;
                usleep(100000);
            }
        } while (microtime(true) < $deadline);

        throw new DockerUnavailableException('The Docker MariaDB server did not become ready within 60 seconds: '.$lastException->getMessage(), 0, $lastException);
    }
}
