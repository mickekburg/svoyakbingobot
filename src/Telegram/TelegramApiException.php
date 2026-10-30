<?php

declare(strict_types=1);

namespace App\Telegram;

/** The Bot API answered with ok=false, or the request never got an answer (errorCode 0). Messages never contain the bot token. */
final class TelegramApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $errorCode = 0,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $errorCode);
    }

    /** 403: the user blocked the bot or deleted the chat. */
    public function isBotBlocked(): bool
    {
        return $this->errorCode === 403;
    }

    public function isMessageNotModified(): bool
    {
        return $this->errorCode === 400 && str_contains(strtolower($this->getMessage()), 'message is not modified');
    }
}
