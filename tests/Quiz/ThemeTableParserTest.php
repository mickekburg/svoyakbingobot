<?php

declare(strict_types=1);

namespace App\Tests\Quiz;

use App\Import\ThemeTableParser;
use PHPUnit\Framework\TestCase;

final class ThemeTableParserTest extends TestCase
{
    private const HEADER = ['title', 'year', 'director', 'actors', 'genre', 'poster'];

    private function parse(array $rows, ?callable $imageCheck = null, array $header = self::HEADER): \App\Import\ParseResult
    {
        return (new ThemeTableParser())->parse(FilmFixtures::config(), $header, $rows, $imageCheck, 'films.xlsx');
    }

    private function assertHasMessage(array $messages, string $needle): void
    {
        foreach ($messages as $m) {
            if (str_contains($m, $needle)) {
                $this->assertTrue(true);

                return;
            }
        }
        $this->fail(sprintf('No message containing "%s" in: %s', $needle, implode(' | ', $messages)));
    }

    public function testNormalizesTypicalExcelValues(): void
    {
        $result = $this->parse([
            // year arrives as a float, list cells contain stray spaces/duplicates/empty parts
            2 => ['  Довод ', 2020.0, 'Кристофер Нолан', ' Джон Дэвид Вашингтон ;Роберт Паттинсон; ;Роберт Паттинсон', 'фантастика;боевик', 'posters\\tenet.jpg'],
            // title typed by Excel as a number, year as text
            3 => [1917.0, '2019', 'Сэм Мендес', null, 'драма', './posters/1917.png'],
        ]);

        $this->assertSame([], $result->errors);
        $this->assertSame('Довод', $result->rows[2]['title']);
        $this->assertSame(2020, $result->rows[2]['year']);
        $this->assertSame(['Джон Дэвид Вашингтон', 'Роберт Паттинсон'], $result->rows[2]['actors']);
        $this->assertSame('posters/tenet.jpg', $result->rows[2]['poster']);

        $this->assertSame('1917', $result->rows[3]['title'], 'a numeric title must stay "1917", not "1917.0"');
        $this->assertSame(2019, $result->rows[3]['year']);
        $this->assertNull($result->rows[3]['actors'], 'an empty cell is null');
        $this->assertSame('posters/1917.png', $result->rows[3]['poster']);
    }

    public function testEmptyRowsAreSkippedAndRowNumbersAreKept(): void
    {
        $result = $this->parse([
            2 => ['A', 2000, 'X', 'Y', 'z', null],
            3 => [null, null, null, null, null, null],
            4 => ['  ', '', null, null, null, null],
            5 => ['B', 2001, 'X', 'Y', 'z', null],
        ]);

        $this->assertSame([], $result->errors);
        $this->assertSame([2, 5], array_keys($result->rows));
    }

    public function testErrorsPointToRowAndColumn(): void
    {
        $result = $this->parse([
            2 => ['A', 'abc', 'X', 'Y', 'z', null],
            3 => ['B', 2001.5, 'X', 'Y', 'z', null],
            4 => ['C', 2002, 'X', 'Y', 'z', '../secret.png'],
            5 => ['D', 2003, 'X', 'Y', 'z', 'x.gif'],
            6 => ['E', 2004, 'X', 'Y', 'z', '/abs/path.png'],
            7 => ['OK', 2005, 'X', 'Y', 'z', null],
        ]);

        $this->assertCount(5, $result->errors);
        $this->assertHasMessage($result->errors, 'films.xlsx, row 2, column "year": expected a whole number');
        $this->assertHasMessage($result->errors, 'row 3, column "year"');
        $this->assertHasMessage($result->errors, 'row 4, column "poster": image path must not contain ".."');
        $this->assertHasMessage($result->errors, 'row 5, column "poster": unsupported image type');
        $this->assertHasMessage($result->errors, 'row 6, column "poster": image path must be relative');
        $this->assertSame([7], array_keys($result->rows), 'only the valid row survives (and the import is rejected as a whole anyway)');
    }

    public function testImageCheckIsAskedOncePerPath(): void
    {
        $calls = [];
        $check = static function (string $path) use (&$calls): ?string {
            $calls[] = $path;

            return $path === 'p/missing.png' ? 'image file not found: p/missing.png' : null;
        };

        $result = $this->parse([
            2 => ['A', 2000, 'X', 'Y', 'z', 'p/a.png'],
            3 => ['B', 2001, 'X', 'Y', 'z', 'p/a.png'],
            4 => ['C', 2002, 'X', 'Y', 'z', 'p/missing.png'],
            5 => ['D', 2003, 'X', 'Y', 'z', 'p/missing.png'],
        ], $check);

        $this->assertSame(['p/a.png', 'p/missing.png'], $calls);
        $this->assertCount(2, $result->errors);
        $this->assertHasMessage($result->errors, 'row 4, column "poster": image file not found');
        $this->assertHasMessage($result->errors, 'row 5, column "poster": image file not found');
    }

    public function testHeaderProblems(): void
    {
        $missing = $this->parse([2 => ['A', 2000, 'X', 'Y', 'z', null]], null, ['title', 'year', 'director', 'actors', 'poster']);
        $this->assertCount(1, $missing->errors);
        $this->assertHasMessage($missing->errors, 'missing column "genre"');

        $dup = $this->parse([2 => ['A', 2000, 'X', 'Y', 'z', null]], null, ['title', 'year', 'year', 'director', 'actors', 'genre', 'poster']);
        $this->assertHasMessage($dup->errors, 'duplicate column "year"');

        $extra = $this->parse(
            [2 => ['A', 2000, 'X', 'Y', 'z', null, 'note']],
            null,
            ['Title', ' YEAR ', 'director', 'actors', 'genre', 'poster', 'comment'],
        );
        $this->assertSame([], $extra->errors, 'header matching ignores case and spaces');
        $this->assertHasMessage($extra->warnings, 'column "comment" is not declared');
    }

    public function testFileWithoutDataIsAnError(): void
    {
        $result = $this->parse([2 => [null, null, null, null, null, null]]);
        $this->assertHasMessage($result->errors, 'no data rows found');
    }
}
