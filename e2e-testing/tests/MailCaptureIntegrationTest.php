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

use Contao\E2eTesting\Cache\CacheConfig;
use Contao\E2eTesting\Mail\DockerMailCaptureService;
use Contao\E2eTesting\Mail\MailAssertionsTrait;
use Contao\E2eTesting\Mail\Mailbox;
use Contao\E2eTesting\Mail\MessageQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class MailCaptureIntegrationTest extends TestCase
{
    use MailAssertionsTrait;

    protected function setUp(): void
    {
        if ('1' !== getenv('CONTAO_E2E_TEST_MAIL_CAPTURE')) {
            $this->markTestSkipped('Set CONTAO_E2E_TEST_MAIL_CAPTURE=1 to test real SMTP delivery with Docker.');
        }
    }

    public function testCapturesRealSmtpMailWithBodiesAndAttachments(): void
    {
        $recipient = MessageQuery::uniqueRecipient();
        $query = MessageQuery::to($recipient)->withSubject('Confirm registration');
        $this->assertEmailCount(0, $query);
        $this->sendMail($recipient);
        $message = $this->assertEmailSent($query);

        $this->assertSame('sender@example.test', $message->from());
        $this->assertSame([$recipient], $message->to());
        $this->assertEmailTextContains('Confirm your account', $message);
        $this->assertEmailHtmlContains('<strong>Confirm your account</strong>', $message);
        $this->assertEmailHasAttachment('invoice.txt', $message);
        $this->assertEmailCount(1, $query);
        $this->assertEmailCount(0, MessageQuery::to(MessageQuery::uniqueRecipient()));
    }

    public function testCleanupAllowsReusingTheSameRecipientForTheNextTest(): void
    {
        $recipient = MessageQuery::uniqueRecipient();
        $query = MessageQuery::to($recipient);
        $this->sendMail($recipient);
        $first = $this->assertEmailSent($query);
        $this->resetAssertedMailbox();
        $this->assertEmailCount(0, $query);
        $this->sendMail($recipient);
        $second = $this->assertEmailSent($query);

        $this->assertNotSame($first->id(), $second->id());
        $this->assertEmailCount(1, $query);
    }

    public function testConnectionsAreReusedWithinAProcessAndChildContainersAreRemoved(): void
    {
        $first = $this->service()->connection();
        $this->service()->warmUp();
        $this->assertSame($first, $this->service()->connection());
        $script = <<<'PHP'
            require $argv[1];
            $service = new \Contao\E2eTesting\Mail\DockerMailCaptureService(
                \Contao\E2eTesting\Cache\CacheConfig::forProject($argv[2]),
            );
            echo json_encode($service->connection(), JSON_THROW_ON_ERROR);
            PHP;
        $project = \dirname(__DIR__, 2);
        $child = new Process([PHP_BINARY, '-r', $script, $project.'/vendor/autoload.php', $project]);
        $child->setTimeout(300);
        $child->mustRun();

        $connection = json_decode($child->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotSame($first->apiUrl, $connection['apiUrl']);
        $port = (string) parse_url($connection['apiUrl'], PHP_URL_PORT);
        $inspect = new Process(['docker', 'ps', '--filter', 'publish='.$port, '--format', '{{.Names}}']);
        $inspect->mustRun();
        $this->assertSame('', trim($inspect->getOutput()));
    }

    protected function mailbox(): Mailbox
    {
        return new Mailbox($this->service()->connection());
    }

    private function service(): DockerMailCaptureService
    {
        return new DockerMailCaptureService(CacheConfig::forProject(\dirname(__DIR__, 2)));
    }

    private function sendMail(string $recipient): void
    {
        $dsn = $this->service()->connection()->mailerDsn;
        $socket = stream_socket_client('tcp://'.parse_url($dsn, PHP_URL_HOST).':'.parse_url($dsn, PHP_URL_PORT), $errorCode, $errorMessage, 5);
        $this->assertIsResource($socket, $errorMessage);
        stream_set_timeout($socket, 5);

        try {
            $this->expectReply($socket, '220');

            foreach (['EHLO localhost' => '250', 'MAIL FROM:<sender@example.test>' => '250', 'RCPT TO:<'.$recipient.'>' => '250', 'DATA' => '354'] as $command => $reply) {
                fwrite($socket, $command."\r\n");
                $this->expectReply($socket, $reply);
            }

            fwrite($socket, str_replace("\n", "\r\n", $this->mimeMessage($recipient))."\r\n.\r\n");
            $this->expectReply($socket, '250');
            fwrite($socket, "QUIT\r\n");
            $this->expectReply($socket, '221');
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param resource $socket
     */
    private function expectReply($socket, string $expected): void
    {
        do {
            $line = fgets($socket);
            $this->assertNotFalse($line, 'The SMTP server closed the connection.');
            $this->assertStringStartsWith($expected, $line);
        } while ('-' === ($line[3] ?? ''));
    }

    private function mimeMessage(string $recipient): string
    {
        return <<<MAIL
            From: Sender <sender@example.test>
            To: <$recipient>
            Subject: Confirm registration
            MIME-Version: 1.0
            Content-Type: multipart/mixed; boundary="outer"

            --outer
            Content-Type: multipart/alternative; boundary="inner"

            --inner
            Content-Type: text/plain; charset=utf-8

            Confirm your account
            --inner
            Content-Type: text/html; charset=utf-8

            <strong>Confirm your account</strong>
            --inner--
            --outer
            Content-Type: text/plain
            Content-Disposition: attachment; filename="invoice.txt"

            Invoice attachment
            --outer--
            MAIL;
    }
}
