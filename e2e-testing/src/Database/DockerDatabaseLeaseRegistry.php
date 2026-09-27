<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\E2eTesting\Database;

final class DockerDatabaseLeaseRegistry
{
    /**
     * @var array<string, DockerDatabaseLease>
     */
    private static array $leases = [];

    private static bool $shutdownRegistered = false;

    /**
     * @param \Closure(): DockerDatabaseLease $factory
     */
    public function acquire(string $key, \Closure $factory): void
    {
        if (isset(self::$leases[$key])) {
            return;
        }

        self::$leases[$key] = $factory();

        if (!self::$shutdownRegistered) {
            register_shutdown_function(self::releaseAll(...));
            self::$shutdownRegistered = true;
        }
    }

    public function release(string $key): void
    {
        if (!isset(self::$leases[$key])) {
            return;
        }

        $lease = self::$leases[$key];
        unset(self::$leases[$key]);
        $lease->release();
    }

    private static function releaseAll(): void
    {
        $leases = self::$leases;
        self::$leases = [];

        foreach ($leases as $lease) {
            try {
                $lease->release();
            } catch (\Throwable $exception) {
                error_log('Could not stop a Contao E2E Docker database: '.$exception->getMessage());
            }
        }
    }
}
