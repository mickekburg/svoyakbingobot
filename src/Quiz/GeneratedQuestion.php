<?php

declare(strict_types=1);

namespace App\Quiz;

final readonly class GeneratedQuestion
{
    /** @param list<string> $options */
    public function __construct(
        public int $itemId,
        public string $byValue,
        public string $text,
        public array $options,
        public int $correctIndex,
        public ?string $image,
        /** Extra facts about the winner, shown after the answer (empty = nothing). */
        public string $info = '',
        /** The right answer as it is shown to the player. */
        public string $correctText = '',
        /** Every value that counts as right when the answer is typed (no options); empty for a question with options. */
        public array $accepted = [],
        /** ItemKey of the winner: identifies the item in the player's progress. */
        public string $itemKey = '',
        /** @var list<string> pictures of the winner that are sent after the answer */
        public array $answerImages = [],
        /** > 0 for a "list" question: how many elements the player has to name. */
        public int $listNeed = 0,
    ) {
    }

    public function correctAnswer(): string
    {
        if ($this->correctText !== '' || $this->options === []) {
            return $this->correctText;
        }

        return $this->options[$this->correctIndex];
    }

    /** A question with no options: the player types the answer. */
    public function isFreeText(): bool
    {
        return $this->options === [] && $this->listNeed === 0;
    }

    public function isList(): bool
    {
        return $this->listNeed > 0;
    }
}
