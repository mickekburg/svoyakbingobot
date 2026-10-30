<?php

declare(strict_types=1);

namespace App\Quiz;

/** What the player has achieved with one item in one mode (Leitner box + when it should be repeated). */
final readonly class ItemProgress
{
    public function __construct(
        public string $itemKey,
        public int $box,
        public int $correct,
        public int $wrong,
        public \DateTimeImmutable $dueAt,
    ) {
    }

    public function isDue(\DateTimeImmutable $now): bool
    {
        return $this->dueAt <= $now;
    }

    public function isLearned(): bool
    {
        return $this->box >= Leitner::LEARNED_BOX;
    }
}
