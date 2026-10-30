<?php

declare(strict_types=1);

namespace App\Telegram;

final readonly class SentMessage
{
    public function __construct(
        public int $messageId,
        /** file_id of the largest photo size when a photo was sent; used to skip re-uploading next time. */
        public ?string $fileId = null,
    ) {
    }
}
