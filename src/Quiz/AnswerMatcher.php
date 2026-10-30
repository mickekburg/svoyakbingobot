<?php

declare(strict_types=1);

namespace App\Quiz;

/**
 * Checks a typed answer.
 *
 * Always ignored: letter case, any whitespace, and the difference between «ё» and «е».
 * Typos are forgiven (one swapped, missing, extra or wrong letter; two in long answers), except in answers that
 * contain a digit — years, dates, numbers — which must match exactly: «1999» and «1998» are different answers.
 */
final class AnswerMatcher
{
    public static function normalize(string $text): string
    {
        $text = mb_strtolower((string) preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', '', $text));

        return str_replace('ё', 'е', $text);
    }

    /** How many typos are allowed for an answer of this (normalized) length. */
    public static function allowedTypos(string $normalized): int
    {
        if (preg_match('/\d/u', $normalized) === 1) {
            return 0;
        }
        $length = mb_strlen($normalized);

        return $length <= 4 ? 0 : ($length <= 9 ? 1 : 2);
    }

    /** @param list<string> $accepted every value that counts as right */
    public static function matches(string $typed, array $accepted): bool
    {
        return self::match($typed, $accepted) !== null;
    }

    /**
     * @param list<string> $accepted
     *
     * @return array{value: string, exact: bool}|null the accepted value the answer was taken for
     */
    public static function match(string $typed, array $accepted): ?array
    {
        $typed = self::normalize($typed);
        if ($typed === '') {
            return null;
        }

        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($accepted as $value) {
            $norm = self::normalize($value);
            if ($norm === $typed) {
                return ['value' => $value, 'exact' => true];
            }
            $allowed = self::allowedTypos($norm);
            if ($allowed === 0 || abs(mb_strlen($norm) - mb_strlen($typed)) > $allowed) {
                continue;
            }
            $distance = self::distance($typed, $norm);
            if ($distance <= $allowed && $distance < $bestDistance) {
                $best = $value;
                $bestDistance = $distance;
            }
        }

        return $best === null ? null : ['value' => $best, 'exact' => false];
    }

    /** Damerau–Levenshtein distance (optimal string alignment): a swap of two neighbouring letters is one typo. */
    public static function distance(string $a, string $b): int
    {
        $a = mb_str_split($a);
        $b = mb_str_split($b);
        $n = count($a);
        $m = count($b);
        if ($n === 0 || $m === 0) {
            return max($n, $m);
        }

        $d = [];
        for ($i = 0; $i <= $n; $i++) {
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $m; $j++) {
            $d[0][$j] = $j;
        }
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $d[$n][$m];
    }
}
