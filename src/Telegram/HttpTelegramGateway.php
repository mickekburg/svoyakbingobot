<?php

declare(strict_types=1);

namespace App\Telegram;

use Psr\Log\LoggerInterface;

final class HttpTelegramGateway implements TelegramGateway
{
    public function __construct(
        private readonly TelegramApi $api,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sendMessage(string $chatId, string $text, array $keyboard = []): SentMessage
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'link_preview_options' => ['is_disabled' => true],
        ];
        if ($keyboard !== []) {
            $params['reply_markup'] = self::markup($keyboard);
        }

        return new SentMessage(self::messageId($this->api->call('sendMessage', $params), 'sendMessage'));
    }

    public function sendPhoto(string $chatId, Photo $photo, string $caption, array $keyboard = []): SentMessage
    {
        $params = ['chat_id' => $chatId, 'caption' => $caption];
        if ($keyboard !== []) {
            $params['reply_markup'] = self::markup($keyboard);
        }

        if ($photo->fileId !== null) {
            $result = $this->api->call('sendPhoto', $params + ['photo' => $photo->fileId], 30.0);
        } else {
            $result = $this->api->upload('sendPhoto', $params, 'photo', (string) $photo->path);
        }

        return new SentMessage(self::messageId($result, 'sendPhoto'), self::largestPhotoFileId($result));
    }

    public function editText(string $chatId, int $messageId, string $text, array $keyboard = [], bool $isCaption = false): void
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            // an empty inline_keyboard removes the old buttons; omitting reply_markup would keep them
            'reply_markup' => $keyboard === [] ? ['inline_keyboard' => []] : self::markup($keyboard),
        ];
        $params[$isCaption ? 'caption' : 'text'] = $text;

        try {
            $this->api->call($isCaption ? 'editMessageCaption' : 'editMessageText', $params);
        } catch (TelegramApiException $e) {
            if (!$e->isMessageNotModified()) {
                throw $e;
            }
        }
    }

    public function removeKeyboard(string $chatId, int $messageId): void
    {
        try {
            $this->api->call('editMessageReplyMarkup', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'reply_markup' => ['inline_keyboard' => []],
            ]);
        } catch (TelegramApiException $e) {
            // already without buttons, or the message is gone/too old: nothing to do either way
            $this->logger->info('Could not remove a keyboard', ['message_id' => $messageId, 'error' => $e->getMessage()]);
        }
    }

    public function answerCallback(string $callbackId, ?string $toast = null): void
    {
        $params = ['callback_query_id' => $callbackId];
        if ($toast !== null && $toast !== '') {
            $params['text'] = mb_substr($toast, 0, 200);
        }

        try {
            $this->api->call('answerCallbackQuery', $params, 10.0);
        } catch (TelegramApiException $e) {
            $this->logger->info('answerCallbackQuery failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param list<list<array{text: string, data: string}>> $keyboard
     *
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function markup(array $keyboard): array
    {
        $rows = [];
        foreach ($keyboard as $row) {
            $buttons = [];
            foreach ($row as $button) {
                $buttons[] = ['text' => $button['text'], 'callback_data' => $button['data']];
            }
            $rows[] = $buttons;
        }

        return ['inline_keyboard' => $rows];
    }

    private static function messageId(mixed $result, string $method): int
    {
        if (!is_array($result) || !is_int($result['message_id'] ?? null)) {
            throw new TelegramApiException(sprintf('%s: response has no message_id', $method));
        }

        return $result['message_id'];
    }

    private static function largestPhotoFileId(mixed $result): ?string
    {
        if (!is_array($result) || !isset($result['photo']) || !is_array($result['photo']) || $result['photo'] === []) {
            return null;
        }
        $last = end($result['photo']);

        return is_array($last) && is_string($last['file_id'] ?? null) ? $last['file_id'] : null;
    }
}
