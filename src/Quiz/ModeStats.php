<?php

declare(strict_types=1);

namespace App\Quiz;

final readonly class ModeStats
{
    public function __construct(
        /** Largest number of options a question of this mode can have (already capped by the mode's max_options). 0 = mode is unusable. */
        public int $maxOptions,
        /** Items that can act as a question winner (have both "by" and "what" values, and "show" if required). */
        public int $eligibleItems,
        /** Distinct values of the "what" field across the theme. */
        public int $distinctWhat,
    ) {
    }

    public function isUsable(ModeDef $mode): bool
    {
        return $this->maxOptions >= $mode->minOptions && $this->eligibleItems > 0;
    }
}
