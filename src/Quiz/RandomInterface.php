<?php

declare(strict_types=1);

namespace App\Quiz;

interface RandomInterface
{
    /** Inclusive bounds. */
    public function int(int $min, int $max): int;

    /**
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    public function shuffle(array $items): array;

    /**
     * Pick $count distinct elements (fewer if the list is shorter), in random order.
     *
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    public function sample(array $items, int $count): array;
}
