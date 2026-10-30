<?php

declare(strict_types=1);

namespace App\Quiz;

/**
 * Builds a question from a (by, what) field pair.
 *
 * Rule that guarantees exactly one correct option:
 *   - pick a winner item and one value v of its "by" field (a random list element for list fields);
 *   - correctSet(v) = every "what" value of ANY item whose "by" field contains v;
 *   - the correct option is one "what" value of the winner (always inside correctSet(v));
 *   - distractors are distinct "what" values that are NOT in correctSet(v).
 * Comparison is by value (exact string match), never by item, so duplicates (remakes with the
 * same title, many films from one year) cannot produce a second correct option.
 *
 * With $optionsCount = 0 the question has no options at all: the player types the answer and every value of
 * correctSet(v) is accepted.
 */
final class QuestionGenerator
{
    public function __construct(private readonly RandomInterface $random)
    {
    }

    /**
     * @param list<ItemRecord> $items
     * @param list<int>        $usedIds    winners that must not be reused in this round
     * @param array<int, int>  $priorities item id => priority (smaller = sooner); only the best group of winners is used
     *
     * @return GeneratedQuestion|null null when no winner can produce a question with $optionsCount options
     */
    public function generate(ThemeConfig $config, ModeDef $mode, array $items, int $optionsCount, array $usedIds = [], array $priorities = []): ?GeneratedQuestion
    {
        if ($mode->isList()) {
            return $this->generateList($config, $mode, $items, $optionsCount, $usedIds, $priorities);
        }
        if ($optionsCount !== 0 && ($optionsCount < $mode->minOptions || $optionsCount > $mode->maxOptions)) {
            throw new \InvalidArgumentException(sprintf('optionsCount must be %d..%d for mode "%s"', $mode->minOptions, $mode->maxOptions, $mode->slug));
        }

        $byField = $config->field($mode->by);
        $whatField = $config->field($mode->what);
        $index = $this->buildIndex($mode, $byField, $whatField, $items);

        $candidates = $this->eligibleWinners($mode, $index, $optionsCount, $usedIds);
        if ($candidates === []) {
            return null;
        }
        if ($priorities !== []) {
            $best = min(array_map(static fn (array $c): int => $priorities[$c['item']->id] ?? Leitner::PRIORITY_NEW, $candidates));
            $candidates = array_values(array_filter(
                $candidates,
                static fn (array $c): bool => ($priorities[$c['item']->id] ?? Leitner::PRIORITY_NEW) === $best,
            ));
        }

        /** @var array{item: ItemRecord, byValues: list<string>} $picked */
        $picked = $candidates[$this->random->int(0, count($candidates) - 1)];
        $winner = $picked['item'];
        $byValue = $picked['byValues'][$this->random->int(0, count($picked['byValues']) - 1)];

        $winnerWhat = $index->itemWhat[$winner->id];
        $correctKey = $winnerWhat[$this->random->int(0, count($winnerWhat) - 1)];
        $correctSet = $index->correctSets[$byValue];

        $pool = [];
        foreach ($index->pool as $key => $_display) {
            $key = (string) $key;
            if (!isset($correctSet[$key])) {
                $pool[] = $key;
            }
        }

        $accepted = [];
        if ($optionsCount === 0) {
            $options = [];
            $correctIndex = -1;
            $accepted = array_map(static fn (int|string $k): string => $index->display[(string) $k], array_keys($correctSet));
        } else {
            $distractors = $this->pickDistractors($config, $mode, $items, $index, $winner, $correctKey, $pool, $optionsCount - 1);

            $keys = $this->random->shuffle([$correctKey, ...$distractors]);
            $correctIndex = (int) array_search($correctKey, $keys, true);
            $options = array_map(static fn (string $k): string => $index->display[$k], $keys);
        }

        $image = null;
        if ($byField->type === FieldType::Image) {
            $image = $byValue;
        } elseif ($mode->show !== null) {
            $shown = $winner->attrs[$mode->show] ?? null;
            $image = is_string($shown) && $shown !== '' ? $shown : null;
        }

        return new GeneratedQuestion(
            itemId: $winner->id,
            byValue: $byValue,
            text: str_replace('{value}', $byValue, $mode->template),
            options: $options,
            correctIndex: $correctIndex,
            image: $image,
            info: $this->answerInfo($config, $mode, $winner),
            correctText: $index->display[$correctKey],
            accepted: $accepted,
            itemKey: ItemKey::of($winner),
            answerImages: $this->answerImages($config, $mode, $winner),
        );
    }

    /**
     * "List" mode: every distinct "by" value with the size of the set of correct answers for it.
     *
     * @param list<ItemRecord> $items
     *
     * @return array<string, int> by value => number of distinct correct "what" values
     */
    public function listGroups(ThemeConfig $config, ModeDef $mode, array $items): array
    {
        $index = $this->buildIndex($mode, $config->field($mode->by), $config->field($mode->what), $items);
        $groups = [];
        foreach ($index->correctSets as $value => $set) {
            if ($set !== []) {
                $groups[(string) $value] = count($set);
            }
        }

        return $groups;
    }

    /** Identity of a list question for the player's progress: the mode and the "by" value. */
    public static function listKey(ModeDef $mode, string $byValue): string
    {
        return sha1('list|' . $mode->slug . '|' . $byValue);
    }

    /** The same identity as a positive int (the round remembers asked questions as ints). */
    public static function listId(ModeDef $mode, string $byValue): int
    {
        return (int) hexdec(substr(self::listKey($mode, $byValue), 0, 7));
    }

    /**
     * @param list<ItemRecord> $items
     * @param list<int>        $usedIds
     *
     * @return array<string, int> by value => set size, for the values that can still be asked with $need elements
     */
    private function listCandidates(ThemeConfig $config, ModeDef $mode, array $items, int $need, array $usedIds): array
    {
        $used = array_flip($usedIds);
        $result = [];
        foreach ($this->listGroups($config, $mode, $items) as $value => $size) {
            $value = (string) $value; // numeric keys such as years are turned into ints by PHP
            if ($size >= $need && !isset($used[self::listId($mode, $value)])) {
                $result[$value] = $size;
            }
        }

        return $result;
    }

    /**
     * @param list<ItemRecord> $items
     * @param list<int>        $usedIds
     * @param array<int, int>  $priorities listId => priority
     */
    private function generateList(ThemeConfig $config, ModeDef $mode, array $items, int $need, array $usedIds, array $priorities): ?GeneratedQuestion
    {
        if ($need < 1 || $need > ModeDef::LIST_MAX) {
            throw new \InvalidArgumentException(sprintf('the number to name must be 1..%d for mode "%s"', ModeDef::LIST_MAX, $mode->slug));
        }
        $candidates = array_map('strval', array_keys($this->listCandidates($config, $mode, $items, $need, $usedIds)));
        if ($candidates === []) {
            return null;
        }
        if ($priorities !== []) {
            $best = min(array_map(fn (string $v): int => $priorities[self::listId($mode, $v)] ?? Leitner::PRIORITY_NEW, $candidates));
            $candidates = array_values(array_filter($candidates, fn (string $v): bool => ($priorities[self::listId($mode, $v)] ?? Leitner::PRIORITY_NEW) === $best));
        }
        $byValue = (string) $candidates[$this->random->int(0, count($candidates) - 1)];

        $index = $this->buildIndex($mode, $config->field($mode->by), $config->field($mode->what), $items);
        $accepted = array_map(static fn (int|string $k): string => $index->display[(string) $k], array_keys($index->correctSets[$byValue]));

        return new GeneratedQuestion(
            itemId: self::listId($mode, $byValue),
            byValue: $byValue,
            text: str_replace(['{value}', '{n}'], [$byValue, (string) $need], $mode->template),
            options: [],
            correctIndex: -1,
            image: null,
            accepted: $accepted,
            itemKey: self::listKey($mode, $byValue),
            listNeed: $need,
        );
    }

    /** Removes every "(...)" part (nested ones too) together with the space before it; keeps the text if nothing else is left. */
    public static function stripBrackets(string $text): string
    {
        $result = $text;
        do {
            $before = $result;
            $result = preg_replace('/\s*\([^()]*\)/u', '', $result) ?? $result;
        } while ($result !== $before);
        $result = trim($result);

        return $result === '' ? $text : $result;
    }

    /** @return list<string> the winner's pictures from answer_info (the question's own picture is never repeated) */
    private function answerImages(ThemeConfig $config, ModeDef $mode, ItemRecord $winner): array
    {
        $images = [];
        foreach ($config->answerInfoFor($mode) as $key) {
            if ($config->field($key)->type === FieldType::Image) {
                $images = [...$images, ...$this->values($winner, $config->field($key))];
            }
        }

        return $images;
    }

    /** "Label: value, value" lines about the winner, in the order of the config; empty fields are skipped. */
    private function answerInfo(ThemeConfig $config, ModeDef $mode, ItemRecord $winner): string
    {
        $lines = [];
        foreach ($config->answerInfoFor($mode) as $key) {
            $field = $config->field($key);
            if ($field->type === FieldType::Image) {
                continue;
            }
            $values = $this->values($winner, $field);
            if ($values !== []) {
                $lines[] = $field->label . ': ' . implode(', ', $values);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * How many distinct winners are still available for a question with $optionsCount options.
     *
     * @param list<ItemRecord> $items
     * @param list<int>        $usedIds
     */
    public function countAvailableWinners(ThemeConfig $config, ModeDef $mode, array $items, int $optionsCount, array $usedIds = []): int
    {
        if ($mode->isList()) {
            return count($this->listCandidates($config, $mode, $items, $optionsCount, $usedIds));
        }
        $index = $this->buildIndex($mode, $config->field($mode->by), $config->field($mode->what), $items);

        return count($this->eligibleWinners($mode, $index, $optionsCount, $usedIds));
    }

    /** @param list<ItemRecord> $items */
    public function analyze(ThemeConfig $config, ModeDef $mode, array $items): ModeStats
    {
        if ($mode->isList()) {
            $groups = $this->listGroups($config, $mode, $items);

            return new ModeStats($groups === [] ? 0 : min(max($groups), ModeDef::LIST_MAX), count($groups), count($groups));
        }
        $index = $this->buildIndex($mode, $config->field($mode->by), $config->field($mode->what), $items);

        $best = 0;
        $eligible = 0;
        foreach ($items as $item) {
            $byVals = $index->itemBy[$item->id] ?? [];
            if ($byVals === [] || ($index->itemWhat[$item->id] ?? []) === [] || !$this->hasRequiredImage($mode, $item)) {
                continue;
            }
            $eligible++;
            foreach ($byVals as $v) {
                $best = max($best, $index->capacity[$v] + 1);
            }
        }

        return new ModeStats(min($best, $mode->maxOptions), $eligible, count($index->pool));
    }

    /**
     * @param list<int> $usedIds
     *
     * @return list<array{item: ItemRecord, byValues: list<string>}>
     */
    private function eligibleWinners(ModeDef $mode, GeneratorIndex $index, int $optionsCount, array $usedIds): array
    {
        $used = array_flip($usedIds);
        $result = [];

        foreach ($index->items as $item) {
            if (isset($used[$item->id]) || ($index->itemWhat[$item->id] ?? []) === [] || !$this->hasRequiredImage($mode, $item)) {
                continue;
            }
            $feasible = [];
            foreach ($index->itemBy[$item->id] ?? [] as $v) {
                // one option is the correct value; the others must be verified-wrong values from outside correctSet
                if ($optionsCount === 0 || $index->capacity[$v] >= $optionsCount - 1) {
                    $feasible[] = $v;
                }
            }
            if ($feasible !== []) {
                $result[] = ['item' => $item, 'byValues' => $feasible];
            }
        }

        return $result;
    }

    private function hasRequiredImage(ModeDef $mode, ItemRecord $item): bool
    {
        if ($mode->show === null) {
            return true;
        }
        $v = $item->attrs[$mode->show] ?? null;

        return is_string($v) && $v !== '';
    }

    /**
     * @param list<ItemRecord> $items
     * @param list<string>     $pool     candidate distractor keys (never contains a correct value)
     *
     * @return list<string>
     */
    private function pickDistractors(ThemeConfig $config, ModeDef $mode, array $items, GeneratorIndex $index, ItemRecord $winner, string $correctKey, array $pool, int $need): array
    {
        if ($need <= 0) {
            return [];
        }

        $preferred = [];
        if ($mode->strategy === ModeDef::STRATEGY_NEAR) {
            $center = (int) $correctKey;
            foreach ($pool as $key) {
                if (abs((int) $key - $center) <= $mode->spread) {
                    $preferred[] = $key;
                }
            }
        } elseif ($mode->strategy === ModeDef::STRATEGY_SAME_FIELD && $mode->strategyField !== null) {
            $field = $config->field($mode->strategyField);
            $winnerVals = array_flip($this->values($winner, $field));
            $inPool = array_flip($pool);
            $seen = [];
            foreach ($items as $item) {
                if ($item->id === $winner->id) {
                    continue;
                }
                $overlap = false;
                foreach ($this->values($item, $field) as $v) {
                    if (isset($winnerVals[$v])) {
                        $overlap = true;
                        break;
                    }
                }
                if (!$overlap) {
                    continue;
                }
                foreach ($index->itemWhat[$item->id] ?? [] as $w) {
                    if (isset($inPool[$w]) && !isset($seen[$w])) {
                        $seen[$w] = true;
                        $preferred[] = $w;
                    }
                }
            }
        }

        $chosen = $this->random->sample($preferred, $need);
        if (count($chosen) < $need) {
            $taken = array_flip($chosen);
            $rest = array_values(array_filter($pool, static fn (string $k): bool => !isset($taken[$k])));
            $chosen = [...$chosen, ...$this->random->sample($rest, $need - count($chosen))];
        }

        return array_map('strval', $chosen);
    }

    /** @param list<ItemRecord> $items */
    private function buildIndex(ModeDef $mode, FieldDef $byField, FieldDef $whatField, array $items): GeneratorIndex
    {
        $index = new GeneratorIndex();
        $index->items = $items;
        $unverifiable = [];

        foreach ($items as $item) {
            $byVals = $this->values($item, $byField, $byField->stripBracketsInQuestion || $mode->stripBracketsInQuestion);
            $whatVals = $this->values($item, $whatField, $whatField->stripBracketsInAnswer || $mode->stripBracketsInAnswer);
            $index->itemBy[$item->id] = $byVals;
            $index->itemWhat[$item->id] = $whatVals;

            foreach ($whatVals as $w) {
                $index->display[$w] ??= $w;
                if ($byVals === []) {
                    $unverifiable[$w] = true;
                }
            }
            foreach ($byVals as $v) {
                $index->correctSets[$v] ??= [];
                foreach ($whatVals as $w) {
                    $index->correctSets[$v][$w] = true;
                }
            }
        }

        foreach ($index->display as $w => $text) {
            if (!isset($unverifiable[(string) $w])) {
                $index->pool[(string) $w] = $text;
            }
        }

        $poolSize = count($index->pool);
        foreach ($index->correctSets as $v => $set) {
            $index->capacity[(string) $v] = $poolSize - count(array_intersect_key($set, $index->pool));
        }

        return $index;
    }

    /**
     * Normalized value keys of an item's field (optionally without "(...)" parts): [] for empty, one element for scalars, several for lists.
     *
     * @return list<string>
     */
    private function values(ItemRecord $item, FieldDef $field, bool $stripBrackets = false): array
    {
        $raw = $item->attrs[$field->key] ?? null;
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        $out = [];
        foreach (is_array($raw) ? $raw : [$raw] as $v) {
            $s = trim((string) $v);
            if ($stripBrackets) {
                $s = self::stripBrackets($s);
            }
            if ($s !== '' && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }

        return $out;
    }
}
