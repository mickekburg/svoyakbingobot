<?php

declare(strict_types=1);

namespace App\Quiz;

/**
 * Framework-free view of a theme item. $attrs holds already normalized values:
 * string => string, int => int, list => list<string>, image => string (relative path), empty => null.
 */
final readonly class ItemRecord
{
    /** @param array<string, mixed> $attrs */
    public function __construct(
        public int $id,
        public array $attrs,
    ) {
    }
}
