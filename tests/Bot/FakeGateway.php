<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Telegram\Photo;
use App\Telegram\SentMessage;
use App\Telegram\TelegramApiException;
use App\Telegram\TelegramGateway;

/** Records everything the bot sends and keeps the current text/buttons of every message, like a chat would. */
final class FakeGateway implements TelegramGateway
{
    private int $nextId = 100;

    /** @var array<int, array{chat: string, text: string, keyboard: array<mixed>, photo: bool}> message id => what is on screen */
    public array $live = [];

    /** @var list<array{id: int, chat: string, text: string, keyboard: array<mixed>, photo: ?Photo}> */
    public array $sent = [];

    /** @var list<array{id: int, text: string, caption: bool}> */
    public array $edits = [];

    /** @var list<int> */
    public array $keyboardsRemoved = [];

    /** @var list<?string> one entry per answered callback */
    public array $toasts = [];

    /** Simulates Telegram rejecting a stored file_id. */
    public bool $rejectFileIds = false;

    public function sendMessage(string $chatId, string $text, array $keyboard = []): SentMessage
    {
        $id = $this->nextId++;
        $this->live[$id] = ['chat' => $chatId, 'text' => $text, 'keyboard' => $keyboard, 'photo' => false];
        $this->sent[] = ['id' => $id, 'chat' => $chatId, 'text' => $text, 'keyboard' => $keyboard, 'photo' => null];

        return new SentMessage($id);
    }

    public function sendPhoto(string $chatId, Photo $photo, string $caption, array $keyboard = []): SentMessage
    {
        if ($photo->isFileId() && $this->rejectFileIds) {
            throw new TelegramApiException('sendPhoto: Bad Request: wrong file identifier', 400);
        }

        $id = $this->nextId++;
        $this->live[$id] = ['chat' => $chatId, 'text' => $caption, 'keyboard' => $keyboard, 'photo' => true];
        $this->sent[] = ['id' => $id, 'chat' => $chatId, 'text' => $caption, 'keyboard' => $keyboard, 'photo' => $photo];

        return new SentMessage($id, $photo->isFileId() ? null : 'FID-' . basename((string) $photo->path));
    }

    public function editText(string $chatId, int $messageId, string $text, array $keyboard = [], bool $isCaption = false): void
    {
        if (!isset($this->live[$messageId])) {
            throw new TelegramApiException('editMessageText: Bad Request: message to edit not found', 400);
        }
        $this->live[$messageId]['text'] = $text;
        $this->live[$messageId]['keyboard'] = $keyboard;
        $this->edits[] = ['id' => $messageId, 'text' => $text, 'caption' => $isCaption];
    }

    public function removeKeyboard(string $chatId, int $messageId): void
    {
        if (isset($this->live[$messageId])) {
            $this->live[$messageId]['keyboard'] = [];
        }
        $this->keyboardsRemoved[] = $messageId;
    }

    public function answerCallback(string $callbackId, ?string $toast = null): void
    {
        $this->toasts[] = $toast;
    }

    // ---- inspection helpers -------------------------------------------------------------------------------------

    /** @return array{id: int, chat: string, text: string, keyboard: array<mixed>, photo: ?Photo} */
    public function last(): array
    {
        return $this->sent[array_key_last($this->sent)] ?? throw new \LogicException('nothing was sent');
    }

    public function lastToast(): ?string
    {
        return $this->toasts[array_key_last($this->toasts)] ?? null;
    }

    /** Callback data of every button on a message, in order. @return list<string> */
    public function buttonData(int $messageId): array
    {
        $data = [];
        foreach ($this->live[$messageId]['keyboard'] ?? [] as $row) {
            foreach ($row as $button) {
                $data[] = $button['data'];
            }
        }

        return $data;
    }

    /** @return list<string> */
    public function buttonTexts(int $messageId): array
    {
        $texts = [];
        foreach ($this->live[$messageId]['keyboard'] ?? [] as $row) {
            foreach ($row as $button) {
                $texts[] = $button['text'];
            }
        }

        return $texts;
    }

    /** Newest message that currently carries a button with this callback data. */
    public function messageWithButton(string $data): ?int
    {
        foreach (array_reverse(array_keys($this->live)) as $id) {
            if (in_array($data, $this->buttonData($id), true)) {
                return $id;
            }
        }

        return null;
    }

    public function dataOfButton(int $messageId, string $text): ?string
    {
        foreach ($this->live[$messageId]['keyboard'] ?? [] as $row) {
            foreach ($row as $button) {
                if ($button['text'] === $text) {
                    return $button['data'];
                }
            }
        }

        return null;
    }
}
