<?php

declare(strict_types=1);

namespace App\Import;

final class ImportReport
{
    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    public int $items = 0;

    /** @var list<array{slug: string, title: string, maxOptions: int, eligible: int, usable: bool}> */
    public array $modes = [];

    public function __construct(public readonly string $theme)
    {
    }

    public function ok(): bool
    {
        return $this->errors === [];
    }
}
