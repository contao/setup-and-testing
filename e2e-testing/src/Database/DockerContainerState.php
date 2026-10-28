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

use Contao\E2eTesting\Exception\DockerUnavailableException;

final readonly class DockerContainerState
{
    public const DATABASE_PATH_LABEL = 'org.contao.e2e.database-path';

    public const DATABASE_CONFIG_LABEL = 'org.contao.e2e.database-config';

    /**
     * @param array<array-key, string> $labels
     */
    public function __construct(
        public bool $running,
        private array $labels,
        private int|null $databasePort,
    ) {
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DockerUnavailableException('Docker returned invalid container information.', 0, $exception);
        }

        if (!\is_array($data) || !\is_bool($data['State']['Running'] ?? null) || !\is_array($data['Config']['Labels'] ?? [])) {
            throw new DockerUnavailableException('Docker returned invalid container information.');
        }

        $port = $data['NetworkSettings']['Ports']['3306/tcp'][0]['HostPort'] ?? null;

        return new self(
            $data['State']['Running'],
            array_filter($data['Config']['Labels'] ?? [], \is_string(...)),
            \is_string($port) && ctype_digit($port) ? (int) $port : null,
        );
    }

    public function usesDatabaseConfiguration(DockerDatabaseConfig $config, string $databaseDirectory): bool
    {
        $path = $this->labels[self::DATABASE_PATH_LABEL] ?? null;
        $configuration = $this->labels[self::DATABASE_CONFIG_LABEL] ?? null;

        return null !== $path
            && null !== $configuration
            && hash_equals(hash('sha256', $databaseDirectory), $path)
            && hash_equals($config->fingerprint(), $configuration);
    }

    public function port(): int
    {
        if (null === $this->databasePort || $this->databasePort < 1 || $this->databasePort > 65535) {
            throw new DockerUnavailableException('Docker did not expose a MariaDB port for the Contao E2E container.');
        }

        return $this->databasePort;
    }
}
