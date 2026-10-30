<?php

declare(strict_types=1);

namespace App\Bot;

final readonly class PresentedQuestion
{
    public function __construct(
        public int $messageId,
        /** The exact text/caption that was sent; the answer is appended to it later. */
        public string $text,
        public bool $photo,
    ) {
    }
}
