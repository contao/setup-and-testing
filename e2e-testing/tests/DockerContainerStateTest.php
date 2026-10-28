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

use Contao\E2eTesting\Database\DockerContainerState;
use Contao\E2eTesting\Database\DockerDatabaseConfig;
use Contao\E2eTesting\Exception\DockerUnavailableException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DockerContainerStateTest extends TestCase
{
    public function testReadsRunningStateLabelsAndPortFromOneInspection(): void
    {
        $database = DockerDatabaseConfig::mariaDb();
        $state = DockerContainerState::fromJson(json_encode([
            'State' => ['Running' => true],
            'Config' => ['Labels' => [
                'org.contao.e2e.database-path' => hash('sha256', '/database'),
                'org.contao.e2e.database-config' => $database->fingerprint(),
            ]],
            'NetworkSettings' => ['Ports' => ['3306/tcp' => [['HostPort' => '33061']]]],
        ], JSON_THROW_ON_ERROR));

        $this->assertTrue($state->running);
        $this->assertSame(33061, $state->port());
        $this->assertTrue($state->usesDatabaseConfiguration($database, '/database'));
        $this->assertFalse($state->usesDatabaseConfiguration($database, '/other-database'));
        $this->assertFalse($state->usesDatabaseConfiguration(DockerDatabaseConfig::mysql(), '/database'));
    }

    public function testStoppedContainerCanHaveNoPublishedPortOrLabels(): void
    {
        $state = DockerContainerState::fromJson('{"State":{"Running":false},"Config":{"Labels":null},"NetworkSettings":{"Ports":null}}');

        $this->assertFalse($state->running);
        $this->assertFalse($state->usesDatabaseConfiguration(DockerDatabaseConfig::mariaDb(), '/database'));
        $this->expectException(DockerUnavailableException::class);
        $state->port();
    }

    #[DataProvider('invalidInspections')]
    public function testRejectsInvalidInspectionResponses(string $response): void
    {
        $this->expectException(DockerUnavailableException::class);
        $this->expectExceptionMessage('invalid container information');
        DockerContainerState::fromJson($response);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidInspections(): iterable
    {
        yield 'invalid JSON' => ['invalid'];
        yield 'null' => ['null'];
        yield 'missing state' => ['{}'];
        yield 'invalid running state' => ['{"State":{"Running":"true"}}'];
        yield 'invalid labels' => ['{"State":{"Running":true},"Config":{"Labels":"invalid"}}'];
    }

    #[DataProvider('invalidPorts')]
    public function testRejectsMissingOrInvalidPublishedPorts(string|null $port): void
    {
        $state = DockerContainerState::fromJson(json_encode([
            'State' => ['Running' => true],
            'NetworkSettings' => ['Ports' => ['3306/tcp' => [['HostPort' => $port]]]],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(DockerUnavailableException::class);
        $this->expectExceptionMessage('did not expose a MariaDB port');
        $state->port();
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function invalidPorts(): iterable
    {
        yield 'missing' => [null];
        yield 'not a port' => ['invalid'];
        yield 'zero' => ['0'];
        yield 'out of range' => ['65536'];
    }
}
