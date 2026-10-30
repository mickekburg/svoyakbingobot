<?php

declare(strict_types=1);

namespace App\Telegram;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin client for https://api.telegram.org/bot<token>/<method>.
 * Every failure becomes a TelegramApiException whose message is scrubbed of the token
 * (HTTP client errors quote the full URL, which contains it).
 */
final class TelegramApi
{
    private const BASE = 'https://api.telegram.org';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $botToken,
    ) {
    }

    /**
     * @param array<string, mixed> $params JSON body
     *
     * @throws TelegramApiException
     */
    public function call(string $method, array $params = [], float $timeout = 15.0): mixed
    {
        return $this->send($method, ['json' => $params, 'timeout' => $timeout]);
    }

    /**
     * Multipart request with one local file (sendPhoto with an upload).
     *
     * @param array<string, scalar|array<mixed>|null> $params non-file fields; arrays are sent as JSON strings
     *
     * @throws TelegramApiException
     */
    public function upload(string $method, array $params, string $fileField, string $path, float $timeout = 60.0): mixed
    {
        if (!is_file($path)) {
            throw new TelegramApiException(sprintf('%s: file not found: %s', $method, basename($path)));
        }

        $fields = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            $fields[$key] = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : (string) $value;
        }
        $fields[$fileField] = DataPart::fromPath($path);

        $form = new FormDataPart($fields);

        return $this->send($method, [
            'headers' => $form->getPreparedHeaders()->toArray(),
            'body' => $form->bodyToIterable(),
            'timeout' => $timeout,
        ]);
    }

    /** @param array<string, mixed> $options */
    private function send(string $method, array $options): mixed
    {
        $url = sprintf('%s/bot%s/%s', self::BASE, $this->botToken, $method);

        for ($attempt = 0;; ++$attempt) {
            try {
                $response = $this->http->request('POST', $url, $options);
                $status = $response->getStatusCode();
                $data = $response->toArray(false);
            } catch (TransportExceptionInterface|DecodingExceptionInterface $e) {
                // not chained on purpose: the previous exception's message would carry the token
                throw new TelegramApiException($this->scrub(sprintf('%s: %s', $method, $e->getMessage())));
            }

            if (($data['ok'] ?? false) === true) {
                return $data['result'] ?? null;
            }

            $code = is_int($data['error_code'] ?? null) ? $data['error_code'] : $status;
            $retryAfter = $data['parameters']['retry_after'] ?? null;
            $retryAfter = is_int($retryAfter) ? $retryAfter : null;

            if ($code === 429 && $retryAfter !== null && $retryAfter <= 5 && $attempt === 0) {
                $this->logger->warning('Telegram rate limit, retrying once', ['method' => $method, 'retry_after' => $retryAfter]);
                sleep($retryAfter);
                continue;
            }

            $description = is_string($data['description'] ?? null) ? $data['description'] : 'unknown error';
            throw new TelegramApiException($this->scrub(sprintf('%s: %s', $method, $description)), $code, $retryAfter);
        }
    }

    private function scrub(string $message): string
    {
        return $this->botToken === '' ? $message : str_replace($this->botToken, '<token>', $message);
    }
}
