<?php

declare(strict_types=1);

namespace App\Tests\Quiz;

use App\Stats\StatsPage;
use App\Stats\StatsReport;
use PHPUnit\Framework\TestCase;

final class StatsTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function rounds(): array
    {
        return [
            ['player_id' => 1, 'name' => 'Миша', 'started_at' => '2026-10-29 10:00:00', 'finished_at' => '2026-10-29 10:05:00', 'questions_done' => 10, 'correct' => 8, 'wrong' => 2],
            ['player_id' => 2, 'name' => 'Аня <b>', 'started_at' => '2026-10-29 21:00:00', 'finished_at' => null, 'questions_done' => 4, 'correct' => 1, 'wrong' => 3],
            ['player_id' => 1, 'name' => 'Миша', 'started_at' => '2026-10-30 09:00:00', 'finished_at' => '2026-10-30 09:10:00', 'questions_done' => 5, 'correct' => 5, 'wrong' => 0],
        ];
    }

    public function testTotalsPlayersAndDays(): void
    {
        $zone = new \DateTimeZone(date_default_timezone_get());
        $r = StatsReport::build($this->rounds(), [1 => ['started' => 20, 'learned' => 6]], $zone, new \DateTimeImmutable('2026-10-31 12:00:00'), 7);

        $this->assertSame(2, $r['total']['players']);
        $this->assertSame(3, $r['total']['tours']);
        $this->assertSame(14, $r['total']['correct']);
        $this->assertSame(5, $r['total']['wrong']);
        $this->assertSame(74, $r['total']['accuracy']);
        $this->assertSame(900, $r['total']['seconds'], 'a tour that was not finished has no duration');

        $this->assertSame('Миша', $r['players'][0]['name'], 'the most active first');
        $this->assertSame(15, $r['players'][0]['questions']);
        $this->assertSame(6, $r['players'][0]['learned']);
        $this->assertSame(0, $r['players'][1]['learned']);

        $this->assertCount(7, $r['days'], 'every day of the period is present');
        $this->assertSame('2026-10-31', $r['days'][6]['date']);
        $this->assertSame(0, $r['days'][6]['tours']);
        $this->assertSame(2, $r['days'][4]['tours']);
        $this->assertSame([1 => 10, 2 => 4], $r['days'][4]['players']);
    }

    public function testDaysAreCutInTheDisplayZone(): void
    {
        $utc = date_default_timezone_get();
        $rows = [['player_id' => 1, 'name' => 'А', 'started_at' => '2026-10-29 21:30:00', 'finished_at' => null, 'questions_done' => 1, 'correct' => 1, 'wrong' => 0]];
        $r = StatsReport::build($rows, [], new \DateTimeZone('Asia/Yekaterinburg'), new \DateTimeImmutable('2026-10-31 12:00:00', new \DateTimeZone($utc)), 5);
        $day = array_values(array_filter($r['days'], static fn (array $d): bool => $d['tours'] > 0))[0]['date'];
        $expected = (new \DateTimeImmutable('2026-10-29 21:30:00', new \DateTimeZone($utc)))->setTimezone(new \DateTimeZone('Asia/Yekaterinburg'))->format('Y-m-d');
        $this->assertSame($expected, $day);
    }

    public function testPageEscapesNamesAndHandlesNoGames(): void
    {
        $zone = new \DateTimeZone(date_default_timezone_get());
        $now = new \DateTimeImmutable('2026-10-31 12:00:00');
        $html = StatsPage::render(StatsReport::build($this->rounds(), [], $zone, $now, 7));
        $this->assertStringContainsString('Статистика викторины', $html);
        $this->assertStringContainsString('Аня &lt;b&gt;', $html);
        $this->assertStringNotContainsString('Аня <b>', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringNotContainsString('<script', $html);

        $empty = StatsPage::render(StatsReport::build([], [], $zone, $now, 7));
        $this->assertStringContainsString('Пока никто не играл', $empty);
    }
}
