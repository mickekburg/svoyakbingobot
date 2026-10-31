<?php

declare(strict_types=1);

namespace App\Controller;

use App\Quiz\Leitner;
use App\Stats\StatsPage;
use App\Stats\StatsReport;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public statistics page: who played and when. Open to everyone, so it shows only first names and counters.
 * Days are cut in the time zone STATS_TIMEZONE (for example Asia/Yekaterinburg), by default the server's one.
 */
#[AsController]
final class StatsController
{
    public function __construct(private readonly Connection $db)
    {
    }

    #[Route('/stats', name: 'public_stats', methods: ['GET'])]
    public function __invoke(): Response
    {
        $rounds = $this->db->fetchAllAssociative(
            'SELECT r.player_id, p.first_name, p.username, r.started_at, r.finished_at, r.questions_done, r.correct_count AS correct, r.wrong_count AS wrong
             FROM quiz_round r JOIN quiz_player p ON p.id = r.player_id
             WHERE r.started_at IS NOT NULL ORDER BY r.started_at',
        );
        foreach ($rounds as &$row) {
            $row['name'] = self::displayName($row);
        }
        unset($row);

        $progress = [];
        $rows = $this->db->fetchAllAssociative(
            'SELECT player_id, COUNT(*) AS started, SUM(box >= ?) AS learned FROM quiz_item_progress GROUP BY player_id',
            [Leitner::LEARNED_BOX],
        );
        foreach ($rows as $row) {
            $progress[(int) $row['player_id']] = ['started' => (int) $row['started'], 'learned' => (int) $row['learned']];
        }

        $zoneName = (string) ($_SERVER['STATS_TIMEZONE'] ?? $_ENV['STATS_TIMEZONE'] ?? '');
        try {
            $zone = new \DateTimeZone($zoneName !== '' ? $zoneName : date_default_timezone_get());
        } catch (\Exception) {
            $zone = new \DateTimeZone(date_default_timezone_get());
        }

        $html = StatsPage::render(StatsReport::build($rounds, $progress, $zone, new \DateTimeImmutable()));

        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'public, max-age=60',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /** @param array<string, mixed> $row */
    private static function displayName(array $row): string
    {
        foreach (['first_name', 'username'] as $key) {
            if (is_string($row[$key] ?? null) && trim($row[$key]) !== '') {
                return trim($row[$key]);
            }
        }

        return 'Игрок ' . (int) $row['player_id'];
    }
}
