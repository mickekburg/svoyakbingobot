<?php

declare(strict_types=1);

namespace App\Stats;

/**
 * Numbers for the public statistics page, built from plain rows (no database here, so it is easy to test).
 *
 * Round row: player_id, name, started_at ("Y-m-d H:i:s" in the PHP default time zone), finished_at (or null),
 * questions_done, correct, wrong.
 */
final class StatsReport
{
    /**
     * @param list<array<string, mixed>>                                          $rounds
     * @param array<int, array{started: int, learned: int}>                       $progress player_id => items started / learned
     * @param \DateTimeZone                                                       $displayZone days are cut in this zone
     *
     * @return array{
     *     generatedAt: string,
     *     total: array{players: int, tours: int, answers: int, correct: int, wrong: int, accuracy: ?int, seconds: int},
     *     players: list<array{id: int, name: string, color: int, tours: int, questions: int, correct: int, wrong: int, accuracy: ?int, seconds: int, started: int, learned: int, last: ?string}>,
     *     days: list<array{date: string, tours: int, questions: int, correct: int, wrong: int, accuracy: ?int, players: array<int, int>}>,
     * }
     */
    public static function build(array $rounds, array $progress, \DateTimeZone $displayZone, \DateTimeImmutable $now, int $days = 30): array
    {
        $dbZone = new \DateTimeZone(date_default_timezone_get());
        $now = $now->setTimezone($displayZone);
        $firstDay = $now->setTime(0, 0)->modify(sprintf('-%d days', $days - 1));

        $players = [];
        $byDay = [];
        $total = ['players' => 0, 'tours' => 0, 'answers' => 0, 'correct' => 0, 'wrong' => 0, 'accuracy' => null, 'seconds' => 0];

        foreach ($rounds as $r) {
            $id = (int) $r['player_id'];
            $started = new \DateTimeImmutable((string) $r['started_at'], $dbZone);
            $started = $started->setTimezone($displayZone);
            $seconds = 0;
            if (!empty($r['finished_at'])) {
                $seconds = max(0, (new \DateTimeImmutable((string) $r['finished_at'], $dbZone))->getTimestamp() - $started->getTimestamp());
            }
            $questions = (int) $r['questions_done'];
            $correct = (int) $r['correct'];
            $wrong = (int) $r['wrong'];

            $players[$id] ??= [
                'id' => $id, 'name' => (string) $r['name'], 'color' => count($players), 'tours' => 0, 'questions' => 0, 'correct' => 0,
                'wrong' => 0, 'accuracy' => null, 'seconds' => 0, 'started' => 0, 'learned' => 0, 'last' => null, '_last' => 0,
            ];
            $p = &$players[$id];
            ++$p['tours'];
            $p['questions'] += $questions;
            $p['correct'] += $correct;
            $p['wrong'] += $wrong;
            $p['seconds'] += $seconds;
            if ($started->getTimestamp() > $p['_last']) {
                $p['_last'] = $started->getTimestamp();
                $p['last'] = $started->format('d.m.Y H:i');
            }
            unset($p);

            ++$total['tours'];
            $total['answers'] += $correct + $wrong;
            $total['correct'] += $correct;
            $total['wrong'] += $wrong;
            $total['seconds'] += $seconds;

            if ($started >= $firstDay) {
                $date = $started->format('Y-m-d');
                $byDay[$date] ??= ['date' => $date, 'tours' => 0, 'questions' => 0, 'correct' => 0, 'wrong' => 0, 'accuracy' => null, 'players' => []];
                ++$byDay[$date]['tours'];
                $byDay[$date]['questions'] += $questions;
                $byDay[$date]['correct'] += $correct;
                $byDay[$date]['wrong'] += $wrong;
                $byDay[$date]['players'][$id] = ($byDay[$date]['players'][$id] ?? 0) + $questions;
            }
        }

        foreach ($players as $id => &$p) {
            $p['accuracy'] = self::percent($p['correct'], $p['correct'] + $p['wrong']);
            $p['started'] = $progress[$id]['started'] ?? 0;
            $p['learned'] = $progress[$id]['learned'] ?? 0;
            unset($p['_last']);
        }
        unset($p);
        $total['players'] = count($players);
        $total['accuracy'] = self::percent($total['correct'], $total['correct'] + $total['wrong']);

        // every day of the period is present, also the quiet ones, so the chart has no gaps
        $daysList = [];
        for ($d = $firstDay; $d <= $now; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $row = $byDay[$key] ?? ['date' => $key, 'tours' => 0, 'questions' => 0, 'correct' => 0, 'wrong' => 0, 'accuracy' => null, 'players' => []];
            $row['accuracy'] = self::percent($row['correct'], $row['correct'] + $row['wrong']);
            $daysList[] = $row;
        }

        $list = array_values($players);
        usort($list, static fn (array $a, array $b): int => [$b['questions'], $a['id']] <=> [$a['questions'], $b['id']]);

        return ['generatedAt' => $now->format('d.m.Y H:i'), 'total' => $total, 'players' => $list, 'days' => $daysList];
    }

    private static function percent(int $part, int $whole): ?int
    {
        return $whole > 0 ? (int) round($part * 100 / $whole) : null;
    }
}
