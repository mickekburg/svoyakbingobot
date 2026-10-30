<?php

declare(strict_types=1);

namespace App\Quiz;

final readonly class FieldDef
{
    public function __construct(
        public string $key,
        public FieldType $type,
        public string $label,
        public string $separator = ';',
        /** Cut "(...)" from the value when it is shown in a question ({value}). */
        public bool $stripBracketsInQuestion = false,
        /** Cut "(...)" from the value when it is an answer option. */
        public bool $stripBracketsInAnswer = false,
    ) {
    }
}
