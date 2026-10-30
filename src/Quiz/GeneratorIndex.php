<?php

declare(strict_types=1);

namespace App\Quiz;

/**
 * Per-call lookup tables used by QuestionGenerator.
 *
 * Note: PHP turns numeric-string array keys into ints, so every key read back from these
 * arrays must be cast with (string) before being compared or used as a value.
 *
 * @internal
 */
final class GeneratorIndex
{
    /** @var list<ItemRecord> */
    public array $items = [];

    /** @var array<int, list<string>> item id => "by" values */
    public array $itemBy = [];

    /** @var array<int, list<string>> item id => "what" values */
    public array $itemWhat = [];

    /** @var array<string, array<string, true>> "by" value => set of "what" values that are correct for it */
    public array $correctSets = [];

    /** @var array<string, string> every distinct "what" value => display text */
    public array $display = [];

    /**
     * "what" values that may be shown as WRONG options. A value is only allowed if it never occurs on an item whose
     * "by" value is unknown: such an item could secretly be the right answer (e.g. a film with an empty year may well
     * be from 2020), so it can be neither verified nor used as a distractor.
     *
     * @var array<string, string> value => display text
     */
    public array $pool = [];

    /** @var array<string, int> "by" value => how many distractors are available for it (pool minus its correct set) */
    public array $capacity = [];
}
