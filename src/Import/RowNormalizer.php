<?php

declare(strict_types=1);

namespace App\Import;

use App\Quiz\FieldDef;
use App\Quiz\FieldType;

/**
 * Turns one raw spreadsheet cell into the normalized value stored in quiz_item.attrs.
 * Pure PHP (no spreadsheet or database dependency).
 */
final class RowNormalizer
{
    /**
     * @return array{0: mixed, 1: string|null} [value, error]; value is null for an empty cell
     */
    public function normalize(FieldDef $field, mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
        }
        if ($raw === null || $raw === '') {
            return [null, null];
        }
        if (is_bool($raw) || is_array($raw) || is_object($raw)) {
            return [null, 'unsupported cell value'];
        }

        return match ($field->type) {
            FieldType::String => [$this->text($raw), null],
            FieldType::Int => $this->integer($raw),
            FieldType::List => $this->list($field, $raw),
            FieldType::Image => $this->image($raw),
        };
    }

    private function text(int|float|string $raw): string
    {
        // Excel stores "1917" as the number 1917.0 — bring it back to "1917", not "1917.0".
        if (is_float($raw) && floor($raw) === $raw && abs($raw) < 1e15) {
            return (string) (int) $raw;
        }

        return trim((string) $raw);
    }

    /** @return array{0: int|null, 1: string|null} */
    private function integer(int|float|string $raw): array
    {
        if (is_int($raw)) {
            return [$raw, null];
        }
        if (is_float($raw)) {
            return floor($raw) === $raw && abs($raw) < 1e15
                ? [(int) $raw, null]
                : [null, sprintf('expected a whole number, got "%s"', $raw)];
        }
        if (preg_match('/^[+-]?\d+$/', $raw) === 1) {
            return [(int) $raw, null];
        }
        if (preg_match('/^([+-]?\d+)[.,]0+$/', $raw, $m) === 1) {
            return [(int) $m[1], null];
        }

        return [null, sprintf('expected a whole number, got "%s"', $raw)];
    }

    /** @return array{0: list<string>|null, 1: string|null} */
    private function list(FieldDef $field, int|float|string $raw): array
    {
        $parts = explode($field->separator, $this->text($raw));
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && !in_array($part, $out, true)) {
                $out[] = $part;
            }
        }

        return [$out === [] ? null : $out, null];
    }

    /** @return array{0: string|null, 1: string|null} */
    private function image(int|float|string $raw): array
    {
        $path = str_replace('\\', '/', $this->text($raw));
        $path = preg_replace('#^(\./)+#', '', $path) ?? $path;

        if ($path === '' || str_starts_with($path, '/') || preg_match('#^[A-Za-z]:#', $path) === 1) {
            return [null, sprintf('image path must be relative to the images folder, got "%s"', $path)];
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '') {
                return [null, sprintf('image path must not contain ".." or empty segments: "%s"', $path)];
            }
        }
        if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return [null, sprintf('unsupported image type (use jpg, jpeg, png or webp): "%s"', $path)];
        }

        return [$path, null];
    }
}
