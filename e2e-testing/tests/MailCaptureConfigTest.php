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

use Contao\E2eTesting\Database\DatabaseServerConfig;
use Contao\E2eTesting\Exception\MailCaptureException;
use Contao\E2eTesting\Mail\DockerMailCaptureConfig;
use Contao\E2eTesting\Mail\MailCaptureConfig;
use Contao\E2eTesting\ManagedEdition\ManagedEditionConfig;
use Contao\InstallationRecipe\Composer\ComposerConfig;
use Contao\InstallationRecipe\Recipe\InstallationRecipe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MailCaptureConfigTest extends TestCase
{
    /**
     * @var array<string, string|false>
     */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        foreach (['CONTAO_E2E_MAIL_CAPTURE_API_URL', 'CONTAO_E2E_MAIL_CAPTURE_SMTP_DSN'] as $name) {
            $this->previousEnvironment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $name => $value) {
            putenv(false === $value ? $name : $name.'='.$value);
        }
    }

    public function testUnconfiguredMailCaptureFallsBackToDockerOnlyWhenEnabled(): void
    {
        $config = $this->applicationConfig();

        $this->assertNull(MailCaptureConfig::tryFromEnvironment());
        $this->assertNull($config->mailCapture());
        $this->assertInstanceOf(DockerMailCaptureConfig::class, $config->withMailCapture()->mailCapture());
    }

    public function testEnvironmentSelectsAnExternalServiceWithoutStartingDocker(): void
    {
        putenv('CONTAO_E2E_MAIL_CAPTURE_API_URL=http://capture.example.test:8025');
        putenv('CONTAO_E2E_MAIL_CAPTURE_SMTP_DSN=smtp://smtp.example.test:1025');
        $base = $this->applicationConfig();
        $config = $base->withMailCapture()->withAppEnvironment('dev');
        $connection = $config->mailCaptureConnection();

        $this->assertNull($base->mailCapture());
        $this->assertNotNull($connection);
        $this->assertSame('http://capture.example.test:8025', $connection->apiUrl);
        $this->assertSame('smtp://smtp.example.test:1025', $connection->mailerDsn);
        $this->assertSame(['MAILER_DSN' => $connection->mailerDsn], $config->environmentVariables());
        $this->assertSame([], $config->dockerServices());
        $standalone = MailCaptureConfig::fromEnvironment();

        $this->assertSame($connection->apiUrl, $standalone->apiUrl);
        $this->assertSame($connection->mailerDsn, $standalone->mailerDsn);
    }

    public function testExplicitChoicesBypassTheEnvironment(): void
    {
        // A partial environment must not affect an explicit configuration or disabling capture
        putenv('CONTAO_E2E_MAIL_CAPTURE_API_URL=http://capture.example.test:8025');
        $config = $this->applicationConfig();
        $external = new MailCaptureConfig('http://other.example.test:8025', 'smtp://other.example.test:1025');
        $docker = new DockerMailCaptureConfig();

        $this->assertSame($external, $config->withMailCapture($external)->mailCapture());
        $this->assertSame($docker, $config->withMailCapture($docker)->mailCapture());
        $this->assertNull($config->withMailCapture(null)->mailCapture());
    }

    #[DataProvider('incompleteEnvironments')]
    public function testRejectsPartialConfigurationInsteadOfFallingBackToDocker(string $variable): void
    {
        putenv($variable.'=http://capture.example.test:8025');
        $this->expectException(MailCaptureException::class);
        $this->expectExceptionMessage('Set both CONTAO_E2E_MAIL_CAPTURE_API_URL and CONTAO_E2E_MAIL_CAPTURE_SMTP_DSN');

        $this->applicationConfig()->withMailCapture();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function incompleteEnvironments(): iterable
    {
        yield 'missing SMTP DSN' => ['CONTAO_E2E_MAIL_CAPTURE_API_URL'];
        yield 'missing API URL' => ['CONTAO_E2E_MAIL_CAPTURE_SMTP_DSN'];
    }

    public function testEmptyVariablesAreTreatedAsUnset(): void
    {
        putenv('CONTAO_E2E_MAIL_CAPTURE_API_URL=');
        putenv('CONTAO_E2E_MAIL_CAPTURE_SMTP_DSN=');

        $this->assertNull(MailCaptureConfig::tryFromEnvironment());
    }

    public function testRequiredEnvironmentReportsMissingSettings(): void
    {
        $this->expectException(MailCaptureException::class);
        $this->expectExceptionMessage('CONTAO_E2E_MAIL_CAPTURE_API_URL and CONTAO_E2E_MAIL_CAPTURE_SMTP_DSN');

        MailCaptureConfig::fromEnvironment();
    }

    public function testInvalidEndpointsAreRejected(): void
    {
        putenv('CONTAO_E2E_MAIL_CAPTURE_API_URL=not-a-url');
        putenv('CONTAO_E2E_MAIL_CAPTURE_SMTP_DSN=smtp://capture.example.test:1025');
        $this->expectException(\InvalidArgumentException::class);

        $this->applicationConfig()->withMailCapture();
    }

    private function applicationConfig(): ManagedEditionConfig
    {
        return ManagedEditionConfig::create(
            InstallationRecipe::create(ComposerConfig::managedEdition('^5.7')),
            \dirname(__DIR__, 2),
        )->withDatabase(new DatabaseServerConfig('mysql://localhost'));
    }
}
