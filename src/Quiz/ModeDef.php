<?php

declare(strict_types=1);

namespace App\Quiz;

/**
 * One quiz mode: the question is built from the `by` field of a random item,
 * the options are values of the `what` field.
 */
final readonly class ModeDef
{
    public const STRATEGY_RANDOM = 'random';
    public const STRATEGY_NEAR = 'near';
    public const STRATEGY_SAME_FIELD = 'same_field';

    public const TYPE_CHOICE = 'choice';
    /** The player names N correct elements of the set instead of picking one option. */
    public const TYPE_LIST = 'list';
    public const LIST_MAX = 20;

    public const MIN_OPTIONS_FLOOR = 2;
    public const MAX_OPTIONS_CEIL = 10;

    public function __construct(
        public string $slug,
        public string $title,
        public string $by,
        public string $what,
        public string $template,
        public ?string $show = null,
        public string $strategy = self::STRATEGY_RANDOM,
        public int $spread = 3,
        public ?string $strategyField = null,
        public int $minOptions = self::MIN_OPTIONS_FLOOR,
        public int $maxOptions = self::MAX_OPTIONS_CEIL,
        /** @var list<string>|null fields shown after the answer; null = use the theme's list */
        public ?array $answerInfo = null,
        /** Cut "(...)" from the "by" value shown in this mode's question (the field's own flag works too). */
        public bool $stripBracketsInQuestion = false,
        /** Cut "(...)" from this mode's answer options (the field's own flag works too). */
        public bool $stripBracketsInAnswer = false,
        public string $type = self::TYPE_CHOICE,
    ) {
    }

    public function isList(): bool
    {
        return $this->type === self::TYPE_LIST;
    }
}
