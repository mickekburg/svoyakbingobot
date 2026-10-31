<?php

declare(strict_types=1);

namespace App\Stats;

/** The statistics page as one self-contained HTML document: no scripts, no external files. */
final class StatsPage
{
    private const MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

    /** @param array<string, mixed> $r the result of StatsReport::build */
    public static function render(array $r): string
    {
        $t = $r['total'];
        $kpi = [
            ['Игроков', (string) $t['players']],
            ['Туров', (string) $t['tours']],
            ['Ответов', self::num($t['answers'])],
            ['Верных', $t['accuracy'] === null ? '—' : $t['accuracy'] . '%'],
            ['Время в игре', self::duration($t['seconds'])],
        ];
        $tiles = '';
        foreach ($kpi as [$label, $value]) {
            $tiles .= sprintf('<div class="tile"><div class="v">%s</div><div class="l">%s</div></div>', self::e($value), self::e($label));
        }

        $legend = '';
        foreach ($r['players'] as $p) {
            $legend .= sprintf('<span class="chip"><i class="sw c%d"></i>%s</span>', $p['color'] % 8, self::e($p['name']));
        }

        $body = $t['tours'] === 0
            ? '<p class="empty">Пока никто не играл.</p>'
            : '<section><h2>Вопросов по дням</h2><div class="scroll">' . self::chart($r) . '</div><div class="legend">' . $legend . '</div></section>'
                . '<section><h2>Игроки</h2>' . self::playersTable($r['players']) . '</section>'
                . '<section><h2>Дни</h2>' . self::daysTable($r) . '</section>';

        return '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>Статистика викторины</title><style>' . self::css() . '</style></head><body><main>'
            . '<header><h1>Статистика викторины</h1><p class="sub">Обновлено ' . self::e($r['generatedAt']) . '</p></header>'
            . '<div class="tiles">' . $tiles . '</div>' . $body . '</main></body></html>';
    }

    /** @param array<string, mixed> $r */
    private static function chart(array $r): string
    {
        $days = $r['days'];
        $n = count($days);
        $max = 1;
        foreach ($days as $d) {
            $max = max($max, $d['questions']);
        }
        $w = 720;
        $h = 220;
        $left = 34;
        $bottom = 24;
        $plotH = $h - $bottom - 10;
        $slot = ($w - $left) / max(1, $n);
        $barW = max(2.0, $slot * 0.7);

        $svg = sprintf('<svg viewBox="0 0 %d %d" role="img" aria-label="Вопросов по дням">', $w, $h);
        foreach ([0, 0.5, 1] as $f) {
            $y = 10 + $plotH * (1 - $f);
            $svg .= sprintf('<line x1="%d" x2="%d" y1="%.1f" y2="%.1f" class="grid"/><text x="%d" y="%.1f" class="ax" text-anchor="end">%d</text>', $left, $w, $y, $y, $left - 6, $y + 4, (int) round($max * $f));
        }
        foreach ($days as $i => $d) {
            $x = $left + $i * $slot + ($slot - $barW) / 2;
            $y = 10 + $plotH;
            $title = self::dayLabel($d['date']) . ': ' . $d['questions'] . ' вопр.';
            $svg .= '<g><title>' . self::e($title) . '</title>';
            $byPlayer = $d['players'];
            ksort($byPlayer);
            foreach ($byPlayer as $pid => $count) {
                if ($count <= 0) {
                    continue;
                }
                $bh = $plotH * $count / $max;
                $y -= $bh;
                $svg .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" class="c%d"/>', $x, $y, $barW, $bh, self::colorOf($r['players'], (int) $pid) % 8);
            }
            $svg .= '</g>';
            if ($n <= 10 || $i % 5 === 0 || $i === $n - 1) {
                $svg .= sprintf('<text x="%.1f" y="%d" class="ax" text-anchor="middle">%s</text>', $x + $barW / 2, $h - 6, self::e(substr($d['date'], 8, 2) . '.' . substr($d['date'], 5, 2)));
            }
        }

        return $svg . '</svg>';
    }

    /** @param list<array<string, mixed>> $players */
    private static function colorOf(array $players, int $id): int
    {
        foreach ($players as $p) {
            if ($p['id'] === $id) {
                return $p['color'];
            }
        }

        return 0;
    }

    /** @param list<array<string, mixed>> $players */
    private static function playersTable(array $players): string
    {
        $rows = '';
        foreach ($players as $p) {
            $learned = $p['started'] > 0 ? sprintf('%d из %d', $p['learned'], $p['started']) : '—';
            $rows .= sprintf(
                '<tr><td><i class="sw c%d"></i>%s</td><td class="n">%d</td><td class="n">%s</td><td class="n">%s</td><td class="n">%s</td><td class="n">%s</td><td>%s</td></tr>',
                $p['color'] % 8,
                self::e($p['name']),
                $p['tours'],
                self::num($p['questions']),
                $p['accuracy'] === null ? '—' : $p['accuracy'] . '%',
                self::e(self::duration($p['seconds'])),
                self::e($learned),
                self::e((string) ($p['last'] ?? '—')),
            );
        }

        return '<div class="scroll"><table><thead><tr><th>Игрок</th><th class="n">Туров</th><th class="n">Вопросов</th><th class="n">Верных</th><th class="n">Время</th><th class="n">Выучено</th><th>Последняя игра</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';
    }

    /** @param array<string, mixed> $r */
    private static function daysTable(array $r): string
    {
        $names = [];
        foreach ($r['players'] as $p) {
            $names[$p['id']] = $p;
        }
        $rows = '';
        foreach (array_reverse($r['days']) as $d) {
            if ($d['tours'] === 0) {
                continue;
            }
            $who = '';
            $byPlayer = $d['players'];
            arsort($byPlayer);
            foreach ($byPlayer as $pid => $count) {
                $p = $names[$pid] ?? null;
                if ($p !== null) {
                    $who .= sprintf('<span class="chip"><i class="sw c%d"></i>%s · %d</span>', $p['color'] % 8, self::e($p['name']), $count);
                }
            }
            $rows .= sprintf(
                '<tr><td>%s</td><td class="n">%d</td><td class="n">%s</td><td class="n">%s</td><td>%s</td></tr>',
                self::e(self::dayLabel($d['date'])),
                $d['tours'],
                self::num($d['questions']),
                $d['accuracy'] === null ? '—' : $d['accuracy'] . '%',
                $who,
            );
        }
        if ($rows === '') {
            return '<p class="empty">За последние 30 дней игр не было.</p>';
        }

        return '<div class="scroll"><table><thead><tr><th>День</th><th class="n">Туров</th><th class="n">Вопросов</th><th class="n">Верных</th><th>Кто играл (вопросов)</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';
    }

    private static function dayLabel(string $date): string
    {
        return (int) substr($date, 8, 2) . ' ' . self::MONTHS[(int) substr($date, 5, 2) - 1];
    }

    private static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' с';
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);

        return $h > 0 ? sprintf('%d ч %02d мин', $h, $m) : sprintf('%d мин', $m);
    }

    private static function num(int $n): string
    {
        return number_format($n, 0, '', "\u{00A0}");
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function css(): string
    {
        return ':root{--bg:#f6f7f9;--card:#fff;--text:#1d2330;--muted:#6b7385;--line:#e3e6ec;--c0:#3b6fd4;--c1:#e0803a;--c2:#2f9e6e;--c3:#c2487a;--c4:#8a5cd0;--c5:#c9a227;--c6:#2aa3b8;--c7:#7a8394}'
            . '@media(prefers-color-scheme:dark){:root{--bg:#14171d;--card:#1c2029;--text:#e7eaf0;--muted:#98a1b3;--line:#2b303c;--c0:#6c97ee;--c1:#f0a15f;--c2:#52c796;--c3:#e272a2;--c4:#ab86ec;--c5:#e2c458;--c6:#5cc4d6;--c7:#9aa3b5}}'
            . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}'
            . 'main{max-width:860px;margin:0 auto;padding:24px 16px 48px}h1{font-size:26px;margin:0}h2{font-size:17px;margin:0 0 12px}.sub{color:var(--muted);margin:4px 0 20px}'
            . '.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:28px}'
            . '.tile{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px 16px}.tile .v{font-size:24px;font-weight:650}.tile .l{color:var(--muted);font-size:13px}'
            . 'section{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:18px 16px;margin-bottom:20px}'
            . 'svg{width:100%;min-width:560px;height:auto;display:block}.grid{stroke:var(--line);stroke-width:1}.ax{fill:var(--muted);font-size:11px}'
            . '.legend{margin-top:10px}.chip{display:inline-flex;align-items:center;margin:2px 10px 2px 0;font-size:13px;white-space:nowrap}'
            . '.sw{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px}'
            . '.c0{background:var(--c0);fill:var(--c0)}.c1{background:var(--c1);fill:var(--c1)}.c2{background:var(--c2);fill:var(--c2)}.c3{background:var(--c3);fill:var(--c3)}'
            . '.c4{background:var(--c4);fill:var(--c4)}.c5{background:var(--c5);fill:var(--c5)}.c6{background:var(--c6);fill:var(--c6)}.c7{background:var(--c7);fill:var(--c7)}'
            . '.scroll{overflow-x:auto}table{border-collapse:collapse;width:100%;font-size:14px}th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:top}'
            . 'th{color:var(--muted);font-weight:500;font-size:12px;white-space:nowrap}.n{text-align:right;white-space:nowrap}tbody tr:last-child td{border-bottom:0}'
            . 'td:first-child{white-space:nowrap}.tile .v{font-size:clamp(18px,5vw,24px)}.empty{color:var(--muted)}';
    }
}
