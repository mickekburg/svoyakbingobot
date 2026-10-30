<?php

declare(strict_types=1);

namespace App\Telegram;

/** A picture to send: either a Telegram file_id seen before, or a local file to upload. */
final readonly class Photo
{
    private function __construct(public ?string $fileId, public ?string $path)
    {
    }

    public static function fromFileId(string $fileId): self
    {
        return new self($fileId, null);
    }

    public static function fromPath(string $absolutePath): self
    {
        return new self(null, $absolutePath);
    }

    public function isFileId(): bool
    {
        return $this->fileId !== null;
    }
}
