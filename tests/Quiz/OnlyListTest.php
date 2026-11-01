<?php

declare(strict_types=1);

namespace App\Tests\Quiz;

use App\Import\ThemeTableParser;
use App\Quiz\ItemRecord;
use App\Quiz\ModeDef;
use App\Quiz\PhpRandom;
use App\Quiz\QuestionGenerator;
use App\Quiz\ThemeConfigLoader;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;

/** The optional "only_list" column: rows with data in it are used by "list" modes only. */
final class OnlyListTest extends TestCase
{
    private const HEADER = ['title', 'year', 'director', 'actors', 'genre', 'poster'];

    private function parse(array $header, array $rows): \App\Import\ParseResult
    {
        return (new ThemeTableParser())->parse(FilmFixtures::config(), $header, $rows, null, 'films.xlsx');
    }

    public function testColumnIsOptionalAndAnyDataFlagsTheRow(): void
    {
        $plain = $this->parse(self::HEADER, [2 => ['Довод', 2020, 'Нолан', 'А', 'фантастика', null]]);
        $this->assertSame([], $plain->errors);
        $this->assertTrue(!array_key_exists('only_list', $plain->rows[2]), 'no column: nothing is added, item keys stay as before');

        $r = $this->parse([...self::HEADER, 'only_list'], [
            2 => ['Довод', 2020, 'Нолан', 'А', 'фантастика', null, null],
            3 => ['Душа', 2020, 'Доктер', 'Б', 'мультфильм', null, ''],
            4 => ['Начало', 2010, 'Нолан', 'В', 'фантастика', null, '  '],
            5 => ['Оно', 2017, 'Мускетти', 'Г', 'ужасы', null, 1],
            6 => ['Дюна', 2021, 'Вильнёв', 'Д', 'фантастика', null, 'x'],
        ]);
        $this->assertSame([], $r->errors);
        $this->assertSame([], $r->warnings, 'the column is known, no "ignored column" warning');
        $this->assertTrue(!array_key_exists('only_list', $r->rows[2]));
        $this->assertTrue(!array_key_exists('only_list', $r->rows[3]));
        $this->assertTrue(!array_key_exists('only_list', $r->rows[4]));
        $this->assertTrue($r->rows[5]['only_list']);
        $this->assertTrue($r->rows[6]['only_list']);
    }

    public function testFieldNamedOnlyListIsRejected(): void
    {
        $data = FilmFixtures::configArray();
        $data['fields']['only_list'] = ['type' => 'string', 'label' => 'x'];
        try {
            (new ThemeConfigLoader())->fromArray($data, 'fixture');
            $this->fail('the reserved name must be rejected');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('only_list', $e->getMessage());
        }
    }

    /** @return list<ItemRecord> */
    private function itemsWithFlagged(): array
    {
        $items = FilmFixtures::items();
        $flagged = [];
        foreach ($items as $i => $item) {
            if ($i % 3 === 0) {
                $items[$i] = new ItemRecord($item->id, [...$item->attrs, 'only_list' => true]);
                $flagged[] = $item->id;
            }
        }

        return $items;
    }

    public function testChoiceModesNeverUseFlaggedRows(): void
    {
        $items = $this->itemsWithFlagged();
        $config = FilmFixtures::config();
        $flaggedTitles = [];
        $normalTitles = [];
        foreach ($items as $item) {
            if (!empty($item->attrs['only_list'])) {
                $flaggedTitles[(string) $item->attrs['title']] = true;
            } else {
                $normalTitles[(string) $item->attrs['title']] = true;
            }
        }
        $flaggedTitles = array_diff_key($flaggedTitles, $normalTitles); // a title that also exists in a normal row is fine

        foreach (['title_by_year', 'year_by_title', 'title_by_actor'] as $slug) {
            $mode = $config->mode($slug);
            for ($seed = 1; $seed <= 25; ++$seed) {
                $g = new QuestionGenerator(new PhpRandom(new Xoshiro256StarStar($seed)));
                $q = $g->generate($config, $mode, $items, 3);
                if ($q === null) {
                    continue;
                }
                foreach ([...$q->options, $q->text] as $text) {
                    foreach (array_keys($flaggedTitles) as $title) {
                        $this->assertTrue(!str_contains($text, '«' . $title . '»') && $text !== $title, sprintf('"%s" is list-only but appears in %s', $title, $slug));
                    }
                }
                $this->assertTrue(!in_array($q->itemId, array_map(static fn (ItemRecord $i): int => $i->id, array_filter($items, static fn (ItemRecord $i): bool => !empty($i->attrs['only_list']))), true));
            }
        }

        $g = new QuestionGenerator(new PhpRandom(new Xoshiro256StarStar(1)));
        $all = $g->analyze($config, $config->mode('title_by_year'), FilmFixtures::items());
        $some = $g->analyze($config, $config->mode('title_by_year'), $items);
        $this->assertTrue($some->eligibleItems < $all->eligibleItems, 'flagged rows do not count as questions');
    }

    public function testListModesSeeFlaggedRows(): void
    {
        $data = FilmFixtures::configArray();
        $data['modes'][] = ['slug' => 'list_by_year', 'title' => 'Назови', 'type' => 'list', 'by' => 'year', 'what' => 'title',
            'template' => 'Назови {n} фильмов {value} года'];
        $config = (new ThemeConfigLoader())->fromArray($data, 'fixture');
        $mode = $config->mode('list_by_year');
        $g = new QuestionGenerator(new PhpRandom(new Xoshiro256StarStar(3)));

        $items = FilmFixtures::items();
        $before = $g->listGroups($config, $mode, $items);
        $flaggedAll = [];
        foreach ($items as $item) {
            $flaggedAll[] = new ItemRecord($item->id, [...$item->attrs, 'only_list' => true]);
        }
        $after = $g->listGroups($config, $mode, $flaggedAll);
        $this->assertSame($before, $after, 'list mode uses every row, flagged or not');
        $this->assertCount(count($items), QuestionGenerator::itemsFor($mode, $flaggedAll));
        $this->assertCount(0, QuestionGenerator::itemsFor($config->mode('title_by_year'), $flaggedAll));
    }

    public function testIncludeOnlyListIgnoresTheFlag(): void
    {
        $config = FilmFixtures::config();
        $mode = $config->mode('title_by_year');
        $items = $this->itemsWithFlagged();
        $this->assertCount(count($items), QuestionGenerator::itemsFor($mode, $items, true));
        $this->assertTrue(count(QuestionGenerator::itemsFor($mode, $items)) < count($items));

        $g = new QuestionGenerator(new PhpRandom(new Xoshiro256StarStar(1)));
        $bingo = $g->countAvailableWinners($config, $mode, $items, 3);
        $all = $g->countAvailableWinners($config, $mode, $items, 3, [], true);
        $this->assertTrue($all > $bingo, 'show-all counts flagged rows too');

        $flaggedIds = array_map(static fn (ItemRecord $i): int => $i->id, array_filter($items, static fn (ItemRecord $i): bool => !empty($i->attrs['only_list'])));
        $seen = false;
        for ($seed = 1; $seed <= 60 && !$seen; ++$seed) {
            $g = new QuestionGenerator(new PhpRandom(new Xoshiro256StarStar($seed)));
            $q = $g->generate($config, $mode, $items, 3, [], [], true);
            $seen = $q !== null && in_array($q->itemId, $flaggedIds, true);
        }
        $this->assertTrue($seen, 'a flagged row can be asked when show-all is on');
    }
}
