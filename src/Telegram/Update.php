<?php

declare(strict_types=1);

namespace App\Telegram;

/**
 * The part of a Telegram update the bot cares about: a text message or an inline-button press in a private chat.
 * Everything else (groups, channels, bots, stickers, edits...) is dropped by fromArray() returning null.
 */
final readonly class Update
{
    public function __construct(
        public int $updateId,
        public int $chatId,
        public int $userId,
        public ?string $username,
        public ?string $firstName,
        /** Message text (null for callbacks and for non-text messages). */
        public ?string $text,
        public ?string $callbackData,
        public ?string $callbackId,
        /** Id of the message the pressed button belongs to. */
        public ?int $messageId,
        /** Whether that message is a photo (then its text lives in the caption). */
        public bool $messageHasPhoto,
    ) {
    }

    public function isCallback(): bool
    {
        return $this->callbackData !== null;
    }

    /** "/start@MyBot payload" -> "start"; null for ordinary text. */
    public function command(): ?string
    {
        if ($this->text === null || !str_starts_with($this->text, '/')) {
            return null;
        }
        $first = strtok($this->text, " \t\n");
        if ($first === false) {
            return null;
        }
        $name = strtolower(substr($first, 1));
        $at = strpos($name, '@');
        if ($at !== false) {
            $name = substr($name, 0, $at);
        }

        return $name === '' ? null : $name;
    }

    /** @param array<string, mixed> $raw decoded JSON of one Telegram update */
    public static function fromArray(array $raw): ?self
    {
        $updateId = $raw['update_id'] ?? null;
        if (!is_int($updateId)) {
            return null;
        }

        if (isset($raw['callback_query']) && is_array($raw['callback_query'])) {
            $cq = $raw['callback_query'];
            $message = $cq['message'] ?? null;
            $from = $cq['from'] ?? null;
            $data = $cq['data'] ?? null;
            $id = $cq['id'] ?? null;
            if (!is_array($message) || !is_array($from) || !is_string($data) || !is_string($id)) {
                return null;
            }
            $chat = self::privateChat($message);
            $user = self::human($from);
            $messageId = $message['message_id'] ?? null;
            if ($chat === null || $user === null || !is_int($messageId)) {
                return null;
            }

            return new self(
                $updateId,
                $chat,
                $user['id'],
                $user['username'],
                $user['first_name'],
                null,
                $data,
                $id,
                $messageId,
                !empty($message['photo']),
            );
        }

        $message = $raw['message'] ?? null;
        if (!is_array($message)) {
            return null;
        }
        $chat = self::privateChat($message);
        $user = isset($message['from']) && is_array($message['from']) ? self::human($message['from']) : null;
        if ($chat === null || $user === null) {
            return null;
        }
        $text = isset($message['text']) && is_string($message['text']) ? trim($message['text']) : null;
        if ($text === '') {
            $text = null;
        }

        return new self($updateId, $chat, $user['id'], $user['username'], $user['first_name'], $text, null, null, null, false);
    }

    /** @param array<string, mixed> $message */
    private static function privateChat(array $message): ?int
    {
        $chat = $message['chat'] ?? null;
        if (!is_array($chat) || ($chat['type'] ?? null) !== 'private' || !is_int($chat['id'] ?? null)) {
            return null;
        }

        return $chat['id'];
    }

    /**
     * @param array<string, mixed> $from
     *
     * @return array{id: int, username: ?string, first_name: ?string}|null null for bots and malformed senders
     */
    private static function human(array $from): ?array
    {
        if (!is_int($from['id'] ?? null) || ($from['is_bot'] ?? false) === true) {
            return null;
        }

        return [
            'id' => $from['id'],
            'username' => isset($from['username']) && is_string($from['username']) ? mb_substr($from['username'], 0, 64) : null,
            'first_name' => isset($from['first_name']) && is_string($from['first_name']) ? mb_substr($from['first_name'], 0, 128) : null,
        ];
    }
}
