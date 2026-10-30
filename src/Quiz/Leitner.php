<?php

declare(strict_types=1);

namespace App\Quiz;

/**
 * Spaced repetition by the Leitner boxes.
 *
 *   - a wrong answer sends the item to box 0 (due at once: it comes first in the next tour);
 *   - a right answer to a DUE (or new) item moves it one box up; the higher the box, the longer the pause;
 *   - a right answer to an item that is not due yet changes nothing but the counters (cramming does not count as learning).
 */
final class Leitner
{
    public const MAX_BOX = 5;
    /** From this box on an item counts as learned (it has survived pauses of 1, 3, 7 days and more). */
    public const LEARNED_BOX = 4;

    /** Seconds until the next repetition, by box. */
    private const DAY = 86400;
    private const INTERVALS = [0, self::DAY, 3 * self::DAY, 7 * self::DAY, 16 * self::DAY, 35 * self::DAY];

    /** Priorities: a smaller number is asked sooner. Due items by box, then new ones, then those that can wait. */
    public const PRIORITY_NEW = 10;
    public const PRIORITY_NOT_DUE = 20;

    public static function advance(?ItemProgress $old, string $itemKey, bool $correct, \DateTimeImmutable $now): ItemProgress
    {
        if ($old === null) {
            $box = $correct ? 1 : 0;

            return new ItemProgress($itemKey, $box, $correct ? 1 : 0, $correct ? 0 : 1, self::dueAfter($box, $now));
        }

        if (!$correct) {
            return new ItemProgress($itemKey, 0, $old->correct, $old->wrong + 1, $now);
        }
        if (!$old->isDue($now)) {
            return new ItemProgress($itemKey, $old->box, $old->correct + 1, $old->wrong, $old->dueAt);
        }
        $box = min($old->box + 1, self::MAX_BOX);

        return new ItemProgress($itemKey, $box, $old->correct + 1, $old->wrong, self::dueAfter($box, $now));
    }

    public static function priority(?ItemProgress $progress, \DateTimeImmutable $now): int
    {
        if ($progress === null) {
            return self::PRIORITY_NEW;
        }

        return $progress->isDue($now) ? $progress->box : self::PRIORITY_NOT_DUE + $progress->box;
    }

    private static function dueAfter(int $box, \DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(sprintf('+%d seconds', self::INTERVALS[$box]));
    }
}
