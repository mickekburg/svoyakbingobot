<?php

declare(strict_types=1);

namespace App\Import;

use App\Quiz\FieldType;
use App\Quiz\ThemeConfig;

/**
 * Validates and normalizes a table (header + rows) against a theme config.
 * Pure PHP: the spreadsheet itself is read elsewhere (XlsxReader), image files are checked through a callback.
 */
final class ThemeTableParser
{
    /** Optional service column: any data in the cell makes the row available in "list" modes only. */
    public const ONLY_LIST = 'only_list';

    public function __construct(private readonly RowNormalizer $normalizer = new RowNormalizer())
    {
    }

    /**
     * @param list<mixed>                $header        first spreadsheet row
     * @param array<int, list<mixed>>    $rows          Excel row number (>= 2) => cells
     * @param (callable(string): ?string)|null $imageCheck returns an error text for a bad image path, null when it is fine
     */
    public function parse(ThemeConfig $config, array $header, array $rows, ?callable $imageCheck, string $origin): ParseResult
    {
        $errors = [];
        $warnings = [];

        // ---- header -> column index
        $columns = [];
        foreach ($header as $i => $cell) {
            $name = is_scalar($cell) ? strtolower(trim((string) $cell)) : '';
            if ($name === '') {
                continue;
            }
            if (isset($columns[$name])) {
                $errors[] = sprintf('%s, row 1: duplicate column "%s"', $origin, $name);
                continue;
            }
            $columns[$name] = $i;
        }
        foreach ($config->fields as $key => $_) {
            if (!isset($columns[$key])) {
                $errors[] = sprintf('%s, row 1: missing column "%s"', $origin, $key);
            }
        }
        foreach ($columns as $name => $_) {
            if ($name !== self::ONLY_LIST && !isset($config->fields[$name])) {
                $warnings[] = sprintf('%s, row 1: column "%s" is not declared in the config and is ignored', $origin, $name);
            }
        }
        if ($errors !== []) {
            return new ParseResult([], $errors, $warnings);
        }

        // ---- rows
        $parsed = [];
        $imageCache = [];
        foreach ($rows as $rowNum => $cells) {
            if ($this->isEmpty($cells)) {
                continue;
            }

            $attrs = [];
            $rowOk = true;
            foreach ($config->fields as $key => $field) {
                [$value, $error] = $this->normalizer->normalize($field, $cells[$columns[$key]] ?? null);
                if ($error !== null) {
                    $errors[] = sprintf('%s, row %d, column "%s": %s', $origin, $rowNum, $key, $error);
                    $rowOk = false;
                    continue;
                }
                if ($value !== null && $field->type === FieldType::Image && $imageCheck !== null) {
                    $imageCache[$value] = array_key_exists($value, $imageCache) ? $imageCache[$value] : $imageCheck($value);
                    if ($imageCache[$value] !== null) {
                        $errors[] = sprintf('%s, row %d, column "%s": %s', $origin, $rowNum, $key, $imageCache[$value]);
                        $rowOk = false;
                        continue;
                    }
                }
                $attrs[$key] = $value;
            }

            if ($rowOk) {
                // the key exists only for flagged rows, so the identity (ItemKey) of all other rows stays as it was
                if (isset($columns[self::ONLY_LIST]) && self::hasData($cells[$columns[self::ONLY_LIST]] ?? null)) {
                    $attrs[self::ONLY_LIST] = true;
                }
                $parsed[$rowNum] = $attrs;
            }
        }

        if ($parsed === [] && $errors === []) {
            $errors[] = sprintf('%s: no data rows found', $origin);
        }

        return new ParseResult($parsed, $errors, $warnings);
    }

    private static function hasData(mixed $cell): bool
    {
        return is_scalar($cell) && trim((string) $cell) !== '';
    }

    /** @param list<mixed> $cells */
    private function isEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && trim((string) (is_scalar($cell) ? $cell : 'x')) !== '') {
                return false;
            }
        }

        return true;
    }
}
