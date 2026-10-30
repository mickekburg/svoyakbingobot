<?php

declare(strict_types=1);

namespace App\Quiz;

final readonly class ThemeConfig
{
    /**
     * @param array<string, FieldDef> $fields keyed by field key
     * @param list<ModeDef>           $modes
     */
    public function __construct(
        public string $slug,
        public string $title,
        public string $source,
        public string $imageDir,
        public array $fields,
        public array $modes,
        /** @var list<string> fields shown with the correct answer (the mode's own question/answer fields are skipped) */
        public array $answerInfo = [],
    ) {
    }

    public function field(string $key): FieldDef
    {
        return $this->fields[$key] ?? throw new \OutOfBoundsException(sprintf('Unknown field "%s" in theme "%s"', $key, $this->slug));
    }

    /** @return list<string> the fields to show after the answer: the mode's list (or the theme's), without the fields already in the question/answer */
    public function answerInfoFor(ModeDef $mode): array
    {
        $keys = $mode->answerInfo ?? $this->answerInfo;

        return array_values(array_filter(
            $keys,
            static fn (string $k): bool => $k !== $mode->by && $k !== $mode->what && $k !== $mode->show,
        ));
    }

    public function mode(string $slug): ?ModeDef
    {
        foreach ($this->modes as $mode) {
            if ($mode->slug === $slug) {
                return $mode;
            }
        }

        return null;
    }
}
