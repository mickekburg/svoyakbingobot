<?php

declare(strict_types=1);

namespace App\Bot;

use App\Entity\QuizModeStat;
use App\Entity\QuizPlayer;
use App\Entity\QuizRound;
use App\Entity\QuizTheme;
use App\Quiz\ItemProgress;
use App\Quiz\ItemRecord;

/**
 * Everything the bot needs from the database, in one place.
 * DoctrineQuizStore is the real implementation; tests use an in-memory one, so the whole game
 * (state machine, double taps, restarts) is testable without a database.
 */
interface QuizStore
{
    public function findOrCreatePlayer(int $userId, int $chatId, ?string $username, ?string $firstName): QuizPlayer;

    /** The player's round that is not finished yet (at most one is expected). */
    public function findUnfinishedRound(QuizPlayer $player): ?QuizRound;

    public function findRound(int $roundId): ?QuizRound;

    /** Creates and persists a round (its id is assigned on return). */
    public function createRound(QuizPlayer $player, QuizTheme $theme): QuizRound;

    public function save(QuizRound $round): void;

    /**
     * Runs $fn atomically with the round row locked, so two quick taps (or a retried webhook) are
     * processed one after another and the second one sees the state left by the first.
     * $fn receives the fresh round or null if it does not exist; its changes are saved when it returns.
     * If $fn throws, nothing is saved.
     *
     * @template T
     *
     * @param callable(?QuizRound): T $fn
     *
     * @return T
     */
    public function withLockedRound(int $roundId, callable $fn): mixed;

    /** @return list<QuizTheme> */
    public function themes(): array;

    public function theme(int $themeId): ?QuizTheme;

    /** @return array<string, QuizModeStat> keyed by mode slug */
    public function modeStats(QuizTheme $theme): array;

    /** @return list<ItemRecord> every item of the theme in the framework-free form the generator works with */
    public function itemRecords(QuizTheme $theme): array;

    /** @return array<string, ItemProgress> the player's progress in one mode, keyed by ItemKey */
    public function progress(QuizPlayer $player, QuizTheme $theme, string $modeSlug): array;

    /** @return array<string, array<string, ItemProgress>> the player's progress in the whole theme: mode slug => ItemKey => progress */
    public function themeProgress(QuizPlayer $player, QuizTheme $theme): array;

    /** Applies one answer to the item's progress (Leitner::advance) and saves it. */
    public function recordAnswer(QuizPlayer $player, QuizTheme $theme, string $modeSlug, string $itemKey, bool $correct): void;

    /** file_id of an already uploaded picture, but only while the file on disk is unchanged (same sha1). */
    public function cachedFileId(QuizTheme $theme, string $imagePath, string $sha1): ?string;

    public function rememberFileId(QuizTheme $theme, string $imagePath, string $sha1, string $fileId): void;

    public function forgetFileId(QuizTheme $theme, string $imagePath): void;

    /** @return list<QuizRound> unfinished rounds whose last activity is older than $before */
    public function idleRounds(\DateTimeImmutable $before): array;

    /** Call between updates in a long-running worker: drop cached entities and the (possibly stale) DB connection. */
    public function reset(): void;
}
