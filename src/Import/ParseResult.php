<?php

declare(strict_types=1);

namespace App\Import;

final readonly class ParseResult
{
    /**
     * @param array<int, array<string, mixed>> $rows     Excel row number => normalized attrs
     * @param list<string>                     $errors
     * @param list<string>                     $warnings
     */
    public function __construct(
        public array $rows,
        public array $errors,
        public array $warnings,
    ) {
    }
}
