<?php

declare(strict_types=1);

namespace App\Quiz;

/**
 * Parses and validates a theme config (decoded JSON). Collects every problem
 * and throws one ConfigException so the author sees all errors at once.
 */
final class ThemeConfigLoader
{
    private const KEY_RE = '/^[a-z][a-z0-9_]{0,31}$/';
    private const SLUG_RE = '/^[a-z0-9][a-z0-9_-]{0,31}$/';

    public function loadFile(string $path): ThemeConfig
    {
        if (!is_file($path)) {
            throw new ConfigException([sprintf('%s: file not found', $path)]);
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigException([sprintf('%s: invalid JSON (%s)', basename($path), $e->getMessage())]);
        }

        if (!is_array($data)) {
            throw new ConfigException([sprintf('%s: top level must be an object', basename($path))]);
        }

        return $this->fromArray($data, basename($path));
    }

    /** @param array<string, mixed> $data */
    public function fromArray(array $data, string $origin = 'config'): ThemeConfig
    {
        $errors = [];
        $err = static function (string $msg) use (&$errors, $origin): void {
            $errors[] = sprintf('%s: %s', $origin, $msg);
        };

        $this->rejectUnknownKeys($data, ['slug', 'title', 'source', 'image_dir', 'answer_info', 'fields', 'modes'], 'top level', $err);

        $slug = $data['slug'] ?? null;
        if (!is_string($slug) || !preg_match(self::SLUG_RE, $slug)) {
            $err('"slug" is required: latin letters/digits/-/_, up to 32 chars');
            $slug = '';
        }

        $title = $data['title'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            $err('"title" is required');
            $title = '';
        }

        $source = $data['source'] ?? null;
        if (!is_string($source) || trim($source) === '' || basename($source) !== $source || str_contains($source, '\\')) {
            $err('"source" is required and must be a plain xlsx file name inside the theme folder');
            $source = '';
        }

        $imageDir = $data['image_dir'] ?? 'images';
        if (!is_string($imageDir) || str_contains($imageDir, '..') || str_starts_with($imageDir, '/')) {
            $err('"image_dir" must be a relative path inside the theme folder');
            $imageDir = 'images';
        }

        // ---- fields
        $fields = [];
        $rawFields = $data['fields'] ?? null;
        if (!is_array($rawFields) || $rawFields === []) {
            $err('"fields" must be a non-empty object');
            $rawFields = [];
        }
        foreach ($rawFields as $key => $def) {
            $key = (string) $key;
            if (!preg_match(self::KEY_RE, $key)) {
                $err(sprintf('field key "%s": use lowercase latin letters, digits and _ (start with a letter)', $key));
                continue;
            }
            if ($key === 'only_list') {
                $err('field key "only_list" is reserved: it is the optional spreadsheet column that limits a row to "list" modes');
                continue;
            }
            if (!is_array($def)) {
                $err(sprintf('field "%s": must be an object with "type"', $key));
                continue;
            }
            $type = FieldType::tryFrom((string) ($def['type'] ?? ''));
            if ($type === null) {
                $err(sprintf('field "%s": unknown type "%s" (string|int|list|image)', $key, (string) ($def['type'] ?? '')));
                continue;
            }
            $separator = $def['separator'] ?? ';';
            if (!is_string($separator) || $separator === '') {
                $err(sprintf('field "%s": "separator" must be a non-empty string', $key));
                $separator = ';';
            }
            $label = isset($def['label']) && is_string($def['label']) ? $def['label'] : $key;
            $this->rejectUnknownKeys($def, ['type', 'label', 'separator', 'strip_brackets_in_question', 'strip_brackets_in_answer'], sprintf('field "%s"', $key), $err);
            $flags = [];
            foreach (['strip_brackets_in_question', 'strip_brackets_in_answer'] as $flag) {
                $flags[$flag] = $def[$flag] ?? false;
                if (!is_bool($flags[$flag])) {
                    $err(sprintf('field "%s": "%s" must be true or false', $key, $flag));
                    $flags[$flag] = false;
                } elseif ($flags[$flag] && !in_array($type, [FieldType::String, FieldType::List], true)) {
                    $err(sprintf('field "%s": "%s" only works for string and list fields', $key, $flag));
                    $flags[$flag] = false;
                }
            }
            $fields[$key] = new FieldDef($key, $type, $label, $separator, $flags['strip_brackets_in_question'], $flags['strip_brackets_in_answer']);
        }

        // ---- answer_info (theme default)
        $answerInfo = $this->answerInfoList($data['answer_info'] ?? [], $fields, 'answer_info', $err);

        // ---- modes
        $modes = [];
        $rawModes = $data['modes'] ?? null;
        if (!is_array($rawModes) || $rawModes === []) {
            $err('"modes" must be a non-empty list');
            $rawModes = [];
        }
        $seen = [];
        foreach (array_values($rawModes) as $i => $m) {
            $where = sprintf('mode #%d', $i + 1);
            if (!is_array($m)) {
                $err(sprintf('%s: must be an object', $where));
                continue;
            }
            $mSlug = $m['slug'] ?? null;
            if (!is_string($mSlug) || !preg_match(self::SLUG_RE, $mSlug)) {
                $err(sprintf('%s: "slug" is required: latin letters/digits/-/_, up to 32 chars', $where));
                continue;
            }
            $where = sprintf('mode "%s"', $mSlug);
            if (isset($seen[$mSlug])) {
                $err(sprintf('%s: duplicate slug', $where));
                continue;
            }
            $seen[$mSlug] = true;

            $mTitle = $m['title'] ?? null;
            if (!is_string($mTitle) || trim($mTitle) === '') {
                $err(sprintf('%s: "title" is required', $where));
                continue;
            }

            $ok = true;
            $by = $m['by'] ?? null;
            $what = $m['what'] ?? null;
            if (!is_string($by) || !isset($fields[$by])) {
                $err(sprintf('%s: "by" must be one of the declared fields', $where));
                $ok = false;
            }
            if (!is_string($what) || !isset($fields[$what])) {
                $err(sprintf('%s: "what" must be one of the declared fields', $where));
                $ok = false;
            }
            if ($ok && $by === $what) {
                $err(sprintf('%s: "by" and "what" must differ', $where));
                $ok = false;
            }
            if ($ok && $fields[$what]->type === FieldType::Image) {
                $err(sprintf('%s: "what" cannot be an image field (images are shown in the question only)', $where));
                $ok = false;
            }

            $type = $m['type'] ?? ModeDef::TYPE_CHOICE;
            if (!in_array($type, [ModeDef::TYPE_CHOICE, ModeDef::TYPE_LIST], true)) {
                $err(sprintf('%s: unknown "type" (choice|list)', $where));
                $ok = false;
                $type = ModeDef::TYPE_CHOICE;
            }

            $template = $m['template'] ?? null;
            if (!is_string($template) || trim($template) === '') {
                $err(sprintf('%s: "template" is required', $where));
                $ok = false;
            } elseif ($ok && $fields[$by]->type !== FieldType::Image && !str_contains($template, '{value}')) {
                $err(sprintf('%s: "template" must contain {value}', $where));
                $ok = false;
            }

            if ($type === ModeDef::TYPE_LIST) {
                if (is_string($template) && !str_contains($template, '{n}')) {
                    $err(sprintf('%s: a "list" mode template must contain {n} (how many to name) and {value}', $where));
                    $ok = false;
                }
                if ($ok && $fields[$by]->type === FieldType::Image) {
                    $err(sprintf('%s: a "list" mode cannot use an image field as "by"', $where));
                    $ok = false;
                }
                foreach (['show', 'distractors', 'min_options', 'max_options'] as $unused) {
                    if (isset($m[$unused])) {
                        $err(sprintf('%s: "%s" makes no sense in a "list" mode', $where, $unused));
                        $ok = false;
                    }
                }
            }

            $show = $m['show'] ?? null;
            if ($show !== null) {
                if (!is_string($show) || !isset($fields[$show])) {
                    $err(sprintf('%s: "show" must be a declared field', $where));
                    $ok = false;
                } elseif ($fields[$show]->type !== FieldType::Image) {
                    $err(sprintf('%s: "show" must point to an image field', $where));
                    $ok = false;
                } elseif ($ok && $show === $by) {
                    $err(sprintf('%s: "show" must differ from "by" (an image "by" is always shown)', $where));
                    $ok = false;
                }
            }

            $strategy = ModeDef::STRATEGY_RANDOM;
            $spread = 3;
            $strategyField = null;
            if (isset($m['distractors'])) {
                $d = $m['distractors'];
                if (!is_array($d)) {
                    $err(sprintf('%s: "distractors" must be an object', $where));
                    $ok = false;
                } else {
                    $strategy = (string) ($d['strategy'] ?? ModeDef::STRATEGY_RANDOM);
                    if (!in_array($strategy, [ModeDef::STRATEGY_RANDOM, ModeDef::STRATEGY_NEAR, ModeDef::STRATEGY_SAME_FIELD], true)) {
                        $err(sprintf('%s: unknown distractors.strategy "%s" (random|near|same_field)', $where, $strategy));
                        $ok = false;
                    } elseif ($strategy === ModeDef::STRATEGY_NEAR) {
                        if ($ok && $fields[$what]->type !== FieldType::Int) {
                            $err(sprintf('%s: strategy "near" needs an int "what" field', $where));
                            $ok = false;
                        }
                        $spread = $d['spread'] ?? 3;
                        if (!is_int($spread) || $spread < 1) {
                            $err(sprintf('%s: distractors.spread must be an integer >= 1', $where));
                            $ok = false;
                            $spread = 3;
                        }
                    } elseif ($strategy === ModeDef::STRATEGY_SAME_FIELD) {
                        $strategyField = $d['field'] ?? null;
                        if (!is_string($strategyField) || !isset($fields[$strategyField])) {
                            $err(sprintf('%s: distractors.field must be a declared field', $where));
                            $ok = false;
                            $strategyField = null;
                        } elseif ($fields[$strategyField]->type === FieldType::Image) {
                            $err(sprintf('%s: distractors.field cannot be an image field', $where));
                            $ok = false;
                        } elseif ($strategyField === $what) {
                            $err(sprintf('%s: distractors.field must differ from "what"', $where));
                            $ok = false;
                        }
                    }
                }
            }

            $modeFlags = [];
            foreach (['strip_brackets_in_question' => $by, 'strip_brackets_in_answer' => $what] as $flag => $target) {
                $modeFlags[$flag] = $m[$flag] ?? false;
                if (!is_bool($modeFlags[$flag])) {
                    $err(sprintf('%s: "%s" must be true or false', $where, $flag));
                    $ok = false;
                    $modeFlags[$flag] = false;
                } elseif ($modeFlags[$flag] && is_string($target) && isset($fields[$target]) && !in_array($fields[$target]->type, [FieldType::String, FieldType::List], true)) {
                    $err(sprintf('%s: "%s" only works when the field "%s" is a string or list', $where, $flag, $target));
                    $ok = false;
                }
            }
            $this->rejectUnknownKeys($m, ['slug', 'title', 'by', 'what', 'template', 'show', 'distractors', 'min_options', 'max_options', 'answer_info', 'strip_brackets_in_question', 'strip_brackets_in_answer', 'type'], $where, $err);

            $modeAnswerInfo = null;
            if (array_key_exists('answer_info', $m)) {
                $modeAnswerInfo = $this->answerInfoList($m['answer_info'], $fields, $where . ': answer_info', $err);
            }

            $minOptions = $m['min_options'] ?? ModeDef::MIN_OPTIONS_FLOOR;
            $maxOptions = $m['max_options'] ?? ModeDef::MAX_OPTIONS_CEIL;
            if (!is_int($minOptions) || $minOptions < ModeDef::MIN_OPTIONS_FLOOR || $minOptions > ModeDef::MAX_OPTIONS_CEIL) {
                $err(sprintf('%s: "min_options" must be an integer %d..%d', $where, ModeDef::MIN_OPTIONS_FLOOR, ModeDef::MAX_OPTIONS_CEIL));
                $ok = false;
                $minOptions = ModeDef::MIN_OPTIONS_FLOOR;
            }
            if (!is_int($maxOptions) || $maxOptions < ModeDef::MIN_OPTIONS_FLOOR || $maxOptions > ModeDef::MAX_OPTIONS_CEIL) {
                $err(sprintf('%s: "max_options" must be an integer %d..%d', $where, ModeDef::MIN_OPTIONS_FLOOR, ModeDef::MAX_OPTIONS_CEIL));
                $ok = false;
                $maxOptions = ModeDef::MAX_OPTIONS_CEIL;
            }
            if ($minOptions > $maxOptions) {
                $err(sprintf('%s: "min_options" is greater than "max_options"', $where));
                $ok = false;
            }

            if ($ok) {
                $modes[] = new ModeDef(
                    slug: $mSlug,
                    title: $mTitle,
                    by: $by,
                    what: $what,
                    template: $template,
                    show: $show,
                    strategy: $strategy,
                    spread: $spread,
                    strategyField: $strategyField,
                    minOptions: $type === ModeDef::TYPE_LIST ? 1 : $minOptions,
                    maxOptions: $type === ModeDef::TYPE_LIST ? ModeDef::LIST_MAX : $maxOptions,
                    answerInfo: $modeAnswerInfo,
                    stripBracketsInQuestion: $modeFlags['strip_brackets_in_question'],
                    stripBracketsInAnswer: $modeFlags['strip_brackets_in_answer'],
                    type: $type,
                );
            }
        }

        if ($errors !== []) {
            throw new ConfigException($errors);
        }

        return new ThemeConfig($slug, $title, $source, $imageDir, $fields, $modes, $answerInfo);
    }

    /**
     * A misspelled or misplaced key would be silently ignored and the author would wonder why nothing happens.
     *
     * @param array<array-key, mixed> $data
     * @param list<string>            $allowed
     */
    private function rejectUnknownKeys(array $data, array $allowed, string $where, callable $err): void
    {
        foreach (array_keys($data) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                $err(sprintf('%s: unknown key "%s" (allowed: %s)', $where, (string) $key, implode(', ', $allowed)));
            }
        }
    }

    /**
     * @param array<string, FieldDef> $fields
     *
     * @return list<string>
     */
    private function answerInfoList(mixed $raw, array $fields, string $where, callable $err): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            $err(sprintf('"%s" must be a list of field keys', $where));

            return [];
        }
        $out = [];
        foreach ($raw as $key) {
            if (!is_string($key) || !isset($fields[$key])) {
                $err(sprintf('"%s": "%s" is not a declared field', $where, is_scalar($key) ? (string) $key : 'non-string'));
            } elseif (!in_array($key, $out, true)) {
                $out[] = $key;
            }
        }

        return $out;
    }
}
