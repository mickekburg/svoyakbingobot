<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Bot\QuizStore;
use App\Entity\QuizModeStat;
use App\Entity\QuizPlayer;
use App\Entity\QuizRound;
use App\Entity\QuizTheme;
use App\Quiz\ItemProgress;
use App\Quiz\ItemRecord;
use App\Quiz\Leitner;

/** The QuizStore contract without a database; entity ids are assigned the way auto-increment would. */
final class InMemoryQuizStore implements QuizStore
{
    private int $seq = 0;

    /** @var array<int, QuizPlayer> */
    private array $players = [];

    /** @var array<int, QuizRound> */
    private array $rounds = [];

    /** @var array<int, QuizTheme> */
    private array $themes = [];

    /** @var array<int, array<string, QuizModeStat>> */
    private array $stats = [];

    /** @var array<int, list<ItemRecord>> */
    private array $records = [];

    /** @var array<string, ItemProgress> key: playerId|themeId|mode|itemKey */
    public array $progressRows = [];

    /** @var array<string, array{sha1: string, fileId: string}> */
    private array $files = [];

    /** @param list<ItemRecord> $records */
    public function addTheme(string $slug, string $title, array $records): QuizTheme
    {
        $theme = new QuizTheme($slug, $title);
        $this->assignId($theme);
        $this->themes[(int) $theme->getId()] = $theme;
        $this->records[(int) $theme->getId()] = $records;
        $this->stats[(int) $theme->getId()] = [];

        return $theme;
    }

    public function addModeStat(QuizTheme $theme, string $modeSlug, int $maxOptions, int $eligibleItems): void
    {
        $this->stats[(int) $theme->getId()][$modeSlug] = new QuizModeStat($theme, $modeSlug, $maxOptions, $eligibleItems);
    }

    /** @return list<QuizRound> */
    public function allRounds(): array
    {
        return array_values($this->rounds);
    }

    public function findOrCreatePlayer(int $userId, int $chatId, ?string $username, ?string $firstName): QuizPlayer
    {
        foreach ($this->players as $player) {
            if ($player->getTelegramUserId() === (string) $userId) {
                $player->touch($chatId, $username, $firstName);

                return $player;
            }
        }
        $player = new QuizPlayer($userId, $chatId);
        $player->touch($chatId, $username, $firstName);
        $this->assignId($player);
        $this->players[(int) $player->getId()] = $player;

        return $player;
    }

    public function findUnfinishedRound(QuizPlayer $player): ?QuizRound
    {
        foreach (array_reverse($this->rounds, true) as $round) {
            if ($round->getPlayer()->getId() === $player->getId() && !$round->isFinished()) {
                return $round;
            }
        }

        return null;
    }

    public function findRound(int $roundId): ?QuizRound
    {
        return $this->rounds[$roundId] ?? null;
    }

    public function createRound(QuizPlayer $player, QuizTheme $theme): QuizRound
    {
        $round = new QuizRound($player, $theme);
        $this->assignId($round);
        $this->rounds[(int) $round->getId()] = $round;

        return $round;
    }

    public function save(QuizRound $round): void
    {
    }

    public function withLockedRound(int $roundId, callable $fn): mixed
    {
        return $fn($this->rounds[$roundId] ?? null);
    }

    public function themes(): array
    {
        return array_values($this->themes);
    }

    public function theme(int $themeId): ?QuizTheme
    {
        return $this->themes[$themeId] ?? null;
    }

    public function modeStats(QuizTheme $theme): array
    {
        return $this->stats[(int) $theme->getId()] ?? [];
    }

    public function itemRecords(QuizTheme $theme): array
    {
        return $this->records[(int) $theme->getId()] ?? [];
    }

    public function progress(QuizPlayer $player, QuizTheme $theme, string $modeSlug): array
    {
        return $this->themeProgress($player, $theme)[$modeSlug] ?? [];
    }

    public function themeProgress(QuizPlayer $player, QuizTheme $theme): array
    {
        $prefix = $player->getId() . '|' . $theme->getId() . '|';
        $result = [];
        foreach ($this->progressRows as $key => $row) {
            if (str_starts_with($key, $prefix)) {
                [, , $mode] = explode('|', $key);
                $result[$mode][$row->itemKey] = $row;
            }
        }

        return $result;
    }

    public function recordAnswer(QuizPlayer $player, QuizTheme $theme, string $modeSlug, string $itemKey, bool $correct): void
    {
        $key = implode('|', [$player->getId(), $theme->getId(), $modeSlug, $itemKey]);
        $this->progressRows[$key] = Leitner::advance($this->progressRows[$key] ?? null, $itemKey, $correct, new \DateTimeImmutable());
    }

    public function cachedFileId(QuizTheme $theme, string $imagePath, string $sha1): ?string
    {
        $entry = $this->files[$theme->getId() . '|' . $imagePath] ?? null;

        return $entry !== null && $entry['sha1'] === $sha1 ? $entry['fileId'] : null;
    }

    public function rememberFileId(QuizTheme $theme, string $imagePath, string $sha1, string $fileId): void
    {
        $this->files[$theme->getId() . '|' . $imagePath] = ['sha1' => $sha1, 'fileId' => $fileId];
    }

    public function forgetFileId(QuizTheme $theme, string $imagePath): void
    {
        unset($this->files[$theme->getId() . '|' . $imagePath]);
    }

    public function idleRounds(\DateTimeImmutable $before): array
    {
        return array_values(array_filter(
            $this->rounds,
            static fn (QuizRound $r): bool => !$r->isFinished() && $r->getLastActivityAt() < $before,
        ));
    }

    public function reset(): void
    {
    }

    private function assignId(object $entity): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, ++$this->seq);
    }
}
