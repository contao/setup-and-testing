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

use Psr\Log\AbstractLogger;

final class DatabaseQueryLogger extends AbstractLogger
{
    /**
     * @var list<string>
     */
    private array $queries = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        if (isset($context['sql']) && \is_string($context['sql'])) {
            $this->queries[] = $context['sql'];
        }
    }

    public function clear(): void
    {
        $this->queries = [];
    }

    /**
     * @return list<string>
     */
    public function truncations(): array
    {
        return array_values(array_filter($this->queries, static fn (string $sql): bool => str_starts_with($sql, 'TRUNCATE ')));
    }
}
