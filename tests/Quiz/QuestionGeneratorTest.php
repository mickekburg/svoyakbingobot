<?php

declare(strict_types=1);

namespace App\Tests\Quiz;

use App\Quiz\FieldType;
use App\Quiz\GeneratedQuestion;
use App\Quiz\ItemRecord;
use App\Quiz\ModeDef;
use App\Quiz\PhpRandom;
use App\Quiz\QuestionGenerator;
use App\Quiz\ThemeConfig;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;

final class QuestionGeneratorTest extends TestCase
{
    private ThemeConfig $config;
    /** @var list<ItemRecord> */
    private array $items;

    protected function setUp(): void
    {
        $this->config = FilmFixtures::config();
        $this->items = FilmFixtures::items();
    }

    private function generator(int $seed): QuestionGenerator
    {
        return new QuestionGenerator(new PhpRandom(new Xoshiro256StarStar($seed)));
    }

    /** Independent brute-force oracle: is $option a correct answer for the question's by-value? */
    private function isCorrect(ModeDef $mode, string $byValue, string $option): bool
    {
        foreach ($this->items as $item) {
            if (in_array($byValue, $this->vals($item, $mode->by), true) && in_array($option, $this->vals($item, $mode->what), true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function vals(ItemRecord $item, string $field): array
    {
        $raw = $item->attrs[$field] ?? null;
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        return array_map(static fn ($v): string => trim((string) $v), is_array($raw) ? $raw : [$raw]);
    }

    private function assertWellFormed(ModeDef $mode, GeneratedQuestion $q, int $optionsCount): void
    {
        $this->assertCount($optionsCount, $q->options);
        $this->assertCount($optionsCount, array_unique($q->options), 'options must be distinct');

        $correct = 0;
        foreach ($q->options as $i => $option) {
            if ($this->isCorrect($mode, $q->byValue, $option)) {
                $correct++;
                $this->assertSame($q->correctIndex, $i, 'the only correct option must be at correctIndex');
            }
        }
        $this->assertSame(1, $correct, sprintf('mode %s, question "%s": exactly one correct option expected', $mode->slug, $q->text));
        $this->assertStringNotContainsString('{value}', $q->text);
    }

    public function testOptionsAreSorted(): void
    {
        $checked = 0;
        foreach ($this->config->modes as $mode) {
            if ($mode->type === 'list') {
                continue;
            }
            for ($seed = 1; $seed <= 15; $seed++) {
                try {
                    $q = $this->generator($seed)->generate($this->config, $mode, $this->items, 4);
                } catch (\Throwable) {
                    continue;
                }
                $opts = $q->options;
                if (count($opts) < 2) {
                    continue;
                }
                $numeric = count(array_filter($opts, 'is_numeric')) === count($opts);
                $sorted = $opts;
                if ($numeric) {
                    usort($sorted, static fn ($a, $b) => (float) $a <=> (float) $b);
                } else {
                    usort($sorted, static fn ($a, $b) => strcmp(
                        str_replace('ё', 'е', preg_replace('/^[^\p{L}\p{N}]+/u', '', mb_strtolower($a))),
                        str_replace('ё', 'е', preg_replace('/^[^\p{L}\p{N}]+/u', '', mb_strtolower($b))),
                    ));
                }
                $this->assertSame($sorted, $opts, 'options must be sorted');
                $checked++;
            }
        }
        $this->assertTrue($checked > 0);
    }

    public function testExactlyOneCorrectOptionInEveryMode(): void
    {
        foreach ($this->config->modes as $mode) {
            if ($mode->show !== null || $this->config->field($mode->by)->type === FieldType::Image) {
                continue; // covered separately
            }
            $stats = $this->generator(1)->analyze($this->config, $mode, $this->items);
            $this->assertGreaterThanOrEqual(2, $stats->maxOptions, $mode->slug);

            for ($seed = 1; $seed <= 300; $seed++) {
                $gen = $this->generator($seed);
                $n = 2 + ($seed % ($stats->maxOptions - 1));
                $q = $gen->generate($this->config, $mode, $this->items, $n);
                $this->assertNotNull($q, sprintf('mode %s must be able to produce %d options', $mode->slug, $n));
                $this->assertWellFormed($mode, $q, $n);
            }
        }
    }

    public function testImageModesAreAlsoUnambiguous(): void
    {
        foreach (['title_by_poster', 'year_with_poster'] as $slug) {
            $mode = $this->config->mode($slug);
            $this->assertNotNull($mode);
            $stats = $this->generator(1)->analyze($this->config, $mode, $this->items);
            for ($seed = 1; $seed <= 200; $seed++) {
                $n = 2 + ($seed % ($stats->maxOptions - 1));
                $q = $this->generator($seed)->generate($this->config, $mode, $this->items, $n);
                $this->assertNotNull($q);
                $this->assertWellFormed($mode, $q, $n);
                $this->assertNotNull($q->image, 'image modes always carry a picture');
            }
        }
    }

    public function testPictureIsTheWinnersPoster(): void
    {
        $mode = $this->config->mode('year_with_poster');
        for ($seed = 1; $seed <= 100; $seed++) {
            $q = $this->generator($seed)->generate($this->config, $mode, $this->items, 4);
            $this->assertNotNull($q);
            $winner = array_values(array_filter($this->items, static fn (ItemRecord $i): bool => $i->id === $q->itemId))[0];
            $this->assertSame($winner->attrs['poster'], $q->image);
            $this->assertSame($winner->attrs['title'], $q->byValue);
        }

        $mode = $this->config->mode('title_by_poster');
        $q = $this->generator(5)->generate($this->config, $mode, $this->items, 4);
        $this->assertNotNull($q);
        $this->assertSame($q->byValue, $q->image, 'for an image "by" field the picture is the question value');
    }

    public function testItemsWithoutRequiredValuesNeverAppear(): void
    {
        // ids 24 ("Без года") and 25 ("Без актёров") are incomplete
        $mode = $this->config->mode('title_by_year');
        for ($seed = 1; $seed <= 300; $seed++) {
            $q = $this->generator($seed)->generate($this->config, $mode, $this->items, 5);
            $this->assertNotNull($q);
            $this->assertNotContains(24, [$q->itemId]);
            $this->assertNotContains('Без года', $q->options, 'a film without a year cannot be an option for "by year"');
        }

        $mode = $this->config->mode('title_by_actor');
        for ($seed = 1; $seed <= 300; $seed++) {
            $q = $this->generator($seed)->generate($this->config, $mode, $this->items, 5);
            $this->assertNotNull($q);
            $this->assertNotContains(25, [$q->itemId]);
        }
    }

    public function testListFieldsUseAnyElementButStayUnambiguous(): void
    {
        // Leonardo DiCaprio is in two films; the other options must be films without him.
        $mode = $this->config->mode('title_by_actor');
        $seen = [];
        for ($seed = 1; $seed <= 400; $seed++) {
            $q = $this->generator($seed)->generate($this->config, $mode, $this->items, 4);
            $this->assertNotNull($q);
            $seen[$q->byValue] = true;
            $this->assertWellFormed($mode, $q, 4);
        }
        $this->assertGreaterThan(5, count($seen), 'different actors should be asked about');
    }

    public function testManyFilmsFromOneYearYieldOneCorrectOption(): void
    {
        $mode = $this->config->mode('title_by_year');
        $years = [];
        for ($seed = 1; $seed <= 400; $seed++) {
            $q = $this->generator($seed)->generate($this->config, $mode, $this->items, 6);
            $this->assertNotNull($q);
            $years[$q->byValue] = true;
            $this->assertWellFormed($mode, $q, 6);
        }
        $this->assertArrayHasKey('2020', $years, 'three films are from 2020; the year must still be askable');
    }

    public function testSameTitleRemakesDoNotCreateDuplicates(): void
    {
        // "Оно" exists twice (1990, 2017): as an option it must appear once and never next to a second correct one.
        $mode = $this->config->mode('title_by_year');
        for ($seed = 1; $seed <= 300; $seed++) {
            $q = $this->generator($seed)->generate($this->config, $mode, $this->items, 8);
            $this->assertNotNull($q);
            $this->assertCount(count($q->options), array_unique($q->options));
        }

        $mode = $this->config->mode('year_by_title');
        for ($seed = 1; $seed <= 300; $seed++) {
            $q = $this->generator($seed)->generate($this->config, $mode, $this->items, 4);
            $this->assertNotNull($q);
            if ($q->byValue === 'Оно') {
                $this->assertContains($q->correctAnswer(), ['1990', '2017']);
                $this->assertFalse(
                    in_array('1990', $q->options, true) && in_array('2017', $q->options, true),
                    'both remake years are correct, so they can never be shown together',
                );
            }
        }
    }

    public function testValueOnAnItemWithUnknownByIsNeverADistractor(): void
    {
        // "Оно" also exists with an unknown year: that one might be from 2020, so "Оно" must not be offered
        // as a wrong answer to "which film is from 2020?".
        $items = [
            new ItemRecord(1, ['title' => 'Довод', 'year' => 2020]),
            new ItemRecord(2, ['title' => 'Начало', 'year' => 2010]),
            new ItemRecord(3, ['title' => 'Матрица', 'year' => 1999]),
            new ItemRecord(4, ['title' => 'Оно', 'year' => 2017]),
            new ItemRecord(5, ['title' => 'Оно', 'year' => null]),
            new ItemRecord(6, ['title' => 'Только название', 'year' => null]),
        ];
        $mode = $this->config->mode('title_by_year');
        $this->assertNotNull($mode);

        for ($seed = 1; $seed <= 200; $seed++) {
            $q = $this->generator($seed)->generate($this->config, $mode, $items, 3);
            $this->assertNotNull($q);
            $this->assertNotContains('Только название', $q->options);
            if ($q->byValue !== '2017') {
                $this->assertNotContains('Оно', $q->options, 'an unverifiable title cannot be a wrong option');
            }
        }

        // verified wrong-option pool = {Довод, Начало, Матрица} (3 titles). Asking about 2017 (correct: Оно, which is
        // outside the pool) leaves all 3 as distractors -> 4 options is the ceiling.
        $this->assertSame(4, $this->generator(1)->analyze($this->config, $mode, $items)->maxOptions);
    }

    public function testWinnersAreNotReusedWithinARound(): void
    {
        $mode = $this->config->mode('title_by_year');
        $gen = $this->generator(7);
        $available = $gen->countAvailableWinners($this->config, $mode, $this->items, 4);
        $this->assertGreaterThan(10, $available);

        $used = [];
        for ($i = 0; $i < $available; $i++) {
            $q = $gen->generate($this->config, $mode, $this->items, 4, $used);
            $this->assertNotNull($q, 'question ' . ($i + 1));
            $this->assertNotContains($q->itemId, $used);
            $used[] = $q->itemId;
        }

        $this->assertCount($available, array_unique($used));
        $this->assertNull($gen->generate($this->config, $mode, $this->items, 4, $used), 'no winners left');
        $this->assertSame(0, $gen->countAvailableWinners($this->config, $mode, $this->items, 4, $used));
    }

    public function testReturnsNullWhenThereAreNotEnoughDistinctValues(): void
    {
        $mode = $this->config->mode('year_by_title');
        $stats = $this->generator(1)->analyze($this->config, $mode, $this->items);
        $this->assertSame(10, $stats->maxOptions, 'capped by the mode max_options (10)');
        $this->assertGreaterThan(10, $stats->distinctWhat);

        // Only three distinct years in the whole theme -> 4 options are impossible.
        $few = [
            new ItemRecord(1, ['title' => 'A', 'year' => 2000]),
            new ItemRecord(2, ['title' => 'B', 'year' => 2001]),
            new ItemRecord(3, ['title' => 'C', 'year' => 2002]),
            new ItemRecord(4, ['title' => 'D', 'year' => 2002]),
        ];
        $this->assertNull($this->generator(1)->generate($this->config, $mode, $few, 4));
        $this->assertNotNull($this->generator(1)->generate($this->config, $mode, $few, 3));
        $this->assertSame(3, $this->generator(1)->analyze($this->config, $mode, $few)->maxOptions);
    }

    public function testMaxOptionsAccountsForTheLargestCorrectSet(): void
    {
        // by year -> titles. 6 distinct titles; year 2000 has 4 films, years 2001 and 2002 have one each.
        $items = [
            new ItemRecord(1, ['title' => 'A', 'year' => 2000]),
            new ItemRecord(2, ['title' => 'B', 'year' => 2000]),
            new ItemRecord(3, ['title' => 'C', 'year' => 2000]),
            new ItemRecord(4, ['title' => 'D', 'year' => 2000]),
            new ItemRecord(5, ['title' => 'E', 'year' => 2001]),
            new ItemRecord(6, ['title' => 'F', 'year' => 2002]),
        ];
        $mode = $this->config->mode('title_by_year');
        $this->assertNotNull($mode);

        // best case: ask about 2001 or 2002 -> pool 6 - 1 + 1 = 6 options; asking about 2000 allows only 6 - 4 + 1 = 3
        $this->assertSame(6, $this->generator(1)->analyze($this->config, $mode, $items)->maxOptions);

        $gen = $this->generator(3);
        $this->assertSame(2, $gen->countAvailableWinners($this->config, $mode, $items, 6), 'only E and F can be winners with 6 options');
        $this->assertSame(6, $gen->countAvailableWinners($this->config, $mode, $items, 3), 'with 3 options every film can win');

        for ($seed = 1; $seed <= 100; $seed++) {
            $q = $this->generator($seed)->generate($this->config, $mode, $items, 6);
            $this->assertNotNull($q);
            $this->assertContains($q->byValue, ['2001', '2002']);
            $this->assertCount(6, array_unique($q->options));
        }
    }

    public function testNearStrategyPrefersYearsWithinSpread(): void
    {
        $mode = $this->config->mode('year_near');
        for ($seed = 1; $seed <= 300; $seed++) {
            $q = $this->generator($seed)->generate($this->config, $mode, $this->items, 3);
            $this->assertNotNull($q);
            $this->assertWellFormed($mode, $q, 3);

            $correct = (int) $q->correctAnswer();
            // collect every year of the theme that lies within the spread and is not a correct answer
            $near = [];
            foreach ($this->items as $item) {
                $y = $item->attrs['year'];
                if ($y !== null && $y !== $correct && abs($y - $correct) <= 3) {
                    $near[$y] = true;
                }
            }
            $nearAvailable = count($near);
            $withinSpread = 0;
            foreach ($q->options as $o) {
                if ((int) $o !== $correct && abs((int) $o - $correct) <= 3) {
                    $withinSpread++;
                }
            }
            $this->assertSame(min(2, $nearAvailable), $withinSpread, 'near years must be used first (%d available)');
        }
    }

    public function testSameFieldStrategyPrefersTheSameGenre(): void
    {
        $mode = $this->config->mode('title_same_genre');
        $byId = [];
        foreach ($this->items as $item) {
            $byId[$item->id] = $item;
        }

        for ($seed = 1; $seed <= 300; $seed++) {
            $q = $this->generator($seed)->generate($this->config, $mode, $this->items, 3);
            $this->assertNotNull($q);
            $this->assertWellFormed($mode, $q, 3);

            $winnerGenres = $byId[$q->itemId]->attrs['genre'];
            $candidates = [];
            foreach ($this->items as $item) {
                $title = $item->attrs['title'];
                if ($item->id !== $q->itemId
                    && array_intersect($winnerGenres, $item->attrs['genre']) !== []
                    && !$this->isCorrect($mode, $q->byValue, $title)) {
                    $candidates[$title] = true;
                }
            }

            $sameGenre = 0;
            foreach ($q->options as $i => $option) {
                if ($i !== $q->correctIndex && isset($candidates[$option])) {
                    $sameGenre++;
                }
            }
            // same-genre films come first; random ones only fill what is missing
            $this->assertSame(min(2, count($candidates)), $sameGenre, sprintf('winner #%d, options: %s', $q->itemId, implode(' / ', $q->options)));
        }
    }

    public function testInvalidOptionsCountIsRejected(): void
    {
        $mode = $this->config->mode('title_by_year');
        $this->expectException(\InvalidArgumentException::class);
        $this->generator(1)->generate($this->config, $mode, $this->items, 1);
    }

    public function testEmptyThemeGivesNoQuestion(): void
    {
        $mode = $this->config->mode('title_by_year');
        $this->assertNull($this->generator(1)->generate($this->config, $mode, [], 4));
        $stats = $this->generator(1)->analyze($this->config, $mode, []);
        $this->assertSame(0, $stats->maxOptions);
        $this->assertFalse($stats->isUsable($mode));
    }

    public function testSeededRunsAreReproducible(): void
    {
        $mode = $this->config->mode('title_by_year');
        $a = $this->generator(99)->generate($this->config, $mode, $this->items, 6);
        $b = $this->generator(99)->generate($this->config, $mode, $this->items, 6);
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertSame($a->options, $b->options);
        $this->assertSame($a->text, $b->text);
    }
}
