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
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Mailbox
{
    private readonly HttpClientInterface $client;

    /**
     * @var array<string, string>
     */
    private array $observedMessages = [];

    public function __construct(
        public readonly MailCaptureConfig $config,
        HttpClientInterface|null $client = null,
    ) {
        $this->client = $client ?? HttpClient::create();
    }

    public function reset(): void
    {
        if ([] === $this->observedMessages) {
            return;
        }

        $options = [
            'json' => ['IDs' => array_values($this->observedMessages)],
            'timeout' => 10,
            'max_duration' => 10,
        ];

        try {
            $response = $this->client->request('DELETE', rtrim($this->config->apiUrl, '/').'/api/v1/messages', $options);
            $response->getContent();
        } catch (\Throwable $exception) {
            throw new MailCaptureException('Could not reset captured mail at '.$this->config->apiUrl.': '.$exception->getMessage(), 0, $exception);
        }

        $this->observedMessages = [];
    }

    /**
     * @return list<CapturedMessage>
     */
    public function messages(MessageQuery $query): array
    {
        return iterator_to_array($this->find($query, hrtime(true) / 1e9 + 10), false);
    }

    public function waitForMessage(MessageQuery $query, float $timeout = 5): CapturedMessage
    {
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('The mail wait timeout must be positive and finite.');
        }

        $deadline = hrtime(true) / 1e9 + $timeout;

        do {
            foreach ($this->find($query, $deadline) as $message) {
                return $message;
            }

            $remaining = $deadline - hrtime(true) / 1e9;

            if ($remaining > 0) {
                usleep((int) (min(0.1, $remaining) * 1e6));
            }
        } while (hrtime(true) / 1e9 < $deadline);

        throw new MailCaptureException(\sprintf('No email arrived for %s within %g seconds. Mail capture API: %s', $query->describe(), $timeout, $this->config->apiUrl));
    }

    /**
     * @return \Generator<int, CapturedMessage>
     */
    private function find(MessageQuery $query, float $deadline): \Generator
    {
        $start = 0;

        do {
            $page = $this->get('/api/v1/search?'.http_build_query(['query' => $query->search(), 'start' => $start, 'limit' => 100]), $deadline);

            if (!\is_array($page['messages'] ?? null) || !\is_int($page['messages_count'] ?? null)) {
                throw new MailCaptureException('Mail capture returned an invalid message search response.');
            }

            foreach ($page['messages'] as $summary) {
                if (!\is_array($summary) || !\is_string($summary['ID'] ?? null)) {
                    throw new MailCaptureException('Mail capture returned an invalid message summary.');
                }

                $message = new CapturedMessage($this->get('/api/v1/message/'.rawurlencode($summary['ID']), $deadline));

                if ($query->matches($message)) {
                    $this->observedMessages[$message->id()] = $message->id();

                    yield $message;
                }
            }

            $start += \count($page['messages']);
        } while ([] !== $page['messages'] && $start < $page['messages_count']);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path, float $deadline): array
    {
        $remaining = $deadline - hrtime(true) / 1e9;

        if ($remaining <= 0) {
            throw new MailCaptureException('The mail capture request deadline was exceeded: '.$this->config->apiUrl);
        }

        $options = ['max_duration' => $remaining, 'timeout' => $remaining];

        try {
            return $this->client->request('GET', rtrim($this->config->apiUrl, '/').$path, $options)->toArray();
        } catch (\Throwable $exception) {
            throw new MailCaptureException('Could not read captured mail at '.$this->config->apiUrl.': '.$exception->getMessage(), 0, $exception);
        }
    }
}
