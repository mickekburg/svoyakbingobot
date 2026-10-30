<?php

declare(strict_types=1);

namespace App\Quiz;

use Random\Engine;
use Random\Randomizer;

final class PhpRandom implements RandomInterface
{
    private Randomizer $randomizer;

    /** Pass a seeded engine (e.g. new Random\Engine\Xoshiro256StarStar(42)) for reproducible tests. */
    public function __construct(?Engine $engine = null)
    {
        $this->randomizer = new Randomizer($engine);
    }

    public function int(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }

    public function shuffle(array $items): array
    {
        return array_values($this->randomizer->shuffleArray(array_values($items)));
    }

    public function sample(array $items, int $count): array
    {
        if ($count <= 0 || $items === []) {
            return [];
        }

        return array_slice($this->shuffle($items), 0, $count);
    }
}
