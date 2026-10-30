<?php

declare(strict_types=1);

namespace App\Telegram;

/**
 * The few Bot API calls the game needs. The game logic only knows this interface, so it can be tested
 * with a fake and the HTTP library can be swapped without touching it.
 *
 * A keyboard is a list of rows; a button is ['text' => ..., 'data' => callback_data].
 *
 * @phpstan-type Keyboard list<list<array{text: string, data: string}>>
 */
interface TelegramGateway
{
    /** @param list<list<array{text: string, data: string}>> $keyboard */
    public function sendMessage(string $chatId, string $text, array $keyboard = []): SentMessage;

    /** @param list<list<array{text: string, data: string}>> $keyboard */
    public function sendPhoto(string $chatId, Photo $photo, string $caption, array $keyboard = []): SentMessage;

    /**
     * Replace the text (or the caption of a photo message) and set the buttons.
     * An empty keyboard REMOVES the buttons. "Message is not modified" is not an error.
     *
     * @param list<list<array{text: string, data: string}>> $keyboard
     *
     * @throws TelegramApiException
     */
    public function editText(string $chatId, int $messageId, string $text, array $keyboard = [], bool $isCaption = false): void;

    /** Remove the buttons of a message, keeping its text. */
    public function removeKeyboard(string $chatId, int $messageId): void;

    /** Stop the button's spinner and optionally show a short toast. Failures are ignored (cosmetic). */
    public function answerCallback(string $callbackId, ?string $toast = null): void;
}
