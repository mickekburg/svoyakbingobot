<?php

declare(strict_types=1);

namespace App\Bot;

use App\Entity\QuizRound;
use App\Entity\QuizTheme;
use App\Quiz\GeneratedQuestion;
use App\Quiz\ItemKey;
use App\Quiz\ItemRecord;
use App\Quiz\Leitner;
use App\Quiz\ModeDef;
use App\Quiz\QuestionGenerator;
use App\Quiz\ThemeConfig;
use App\Service\ThemeCatalog;
use Psr\Log\LoggerInterface;

/** Glue between a round (what the player chose) and the pure QuestionGenerator. */
final class QuestionFactory
{
    /** Upper limit of questions in one tour, whatever the theme size. */
    public const MAX_QUESTIONS = 100;

    /** How many winners to try when a picture file has disappeared from disk since the import. */
    private const ATTEMPTS = 5;

    /** @var array<int, array{version: string, records: list<ItemRecord>}> */
    private array $recordsCache = [];

    public function __construct(
        private readonly QuizStore $store,
        private readonly ThemeCatalog $catalog,
        private readonly QuestionGenerator $generator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{config: ThemeConfig, mode: ModeDef}|null null when the theme or mode no longer exists in the config */
    public function context(QuizRound $round): ?array
    {
        $slug = $round->getModeSlug();
        $config = $this->catalog->get($round->getTheme()->getSlug());
        if ($config === null || $slug === null) {
            return null;
        }
        $mode = $config->mode($slug);

        return $mode === null ? null : ['config' => $config, 'mode' => $mode];
    }

    /** How many questions a tour with the round's mode and option count can have (0 = none). */
    public function maxQuestions(QuizRound $round): int
    {
        $context = $this->context($round);
        $options = $round->getOptionsCount();
        if ($context === null || $options === null) {
            return 0;
        }

        try {
            $winners = $this->generator->countAvailableWinners($context['config'], $context['mode'], $this->records($round->getTheme()), $options, [], !$round->isBingoOnly());
        } catch (\InvalidArgumentException) {
            return 0;
        }

        return min($winners, self::MAX_QUESTIONS);
    }

    /** The next question of the round (never repeats a winner already used), or null if none can be built. */
    public function next(QuizRound $round): ?GeneratedQuestion
    {
        $context = $this->context($round);
        $options = $round->getOptionsCount();
        if ($context === null || $options === null) {
            return null;
        }

        $records = $this->records($round->getTheme());
        $skip = $round->getUsedItemIds();

        // repetition first: what the player got wrong or is due to repeat comes before new items, new before learned
        $progress = $round->isSmartSelection() ? $this->store->progress($round->getPlayer(), $round->getTheme(), $context['mode']->slug) : [];
        $now = new \DateTimeImmutable();
        $priorities = [];
        if (!$round->isSmartSelection()) {
            // random selection: no priorities, every item that fits is equally likely
        } elseif ($context['mode']->isList()) {
            foreach ($this->generator->listGroups($context['config'], $context['mode'], $records) as $value => $_size) {
                $value = (string) $value;
                $priorities[QuestionGenerator::listId($context['mode'], $value)] = Leitner::priority($progress[QuestionGenerator::listKey($context['mode'], $value)] ?? null, $now);
            }
        } else {
            foreach (QuestionGenerator::itemsFor($context['mode'], $records, !$round->isBingoOnly()) as $record) {
                $priorities[$record->id] = Leitner::priority($progress[ItemKey::of($record)] ?? null, $now);
            }
        }

        for ($attempt = 0; $attempt < self::ATTEMPTS; ++$attempt) {
            try {
                $question = $this->generator->generate($context['config'], $context['mode'], $records, $options, $skip, $priorities, !$round->isBingoOnly());
            } catch (\InvalidArgumentException $e) {
                $this->logger->error('Option count does not fit the mode any more', ['round' => $round->getId(), 'error' => $e->getMessage()]);

                return null;
            }
            if ($question === null) {
                return null;
            }
            if ($question->image !== null && !is_file($this->catalog->imageAbsolutePath($context['config'], $question->image))) {
                $this->logger->warning('Picture is missing on disk, picking another question', [
                    'theme' => $round->getTheme()->getSlug(),
                    'image' => $question->image,
                ]);
                $skip[] = $question->itemId;
                continue;
            }

            return $question;
        }

        return null;
    }

    /** @return array<string, true> keys that progress rows of this mode can still refer to */
    public function validKeys(QuizTheme $theme, ThemeConfig $config, ModeDef $mode): array
    {
        $records = $this->records($theme);
        $keys = [];
        if ($mode->isList()) {
            foreach ($this->generator->listGroups($config, $mode, $records) as $value => $_size) {
                $keys[QuestionGenerator::listKey($mode, (string) $value)] = true;
            }
        } else {
            foreach (QuestionGenerator::itemsFor($mode, $records) as $record) {
                $keys[ItemKey::of($record)] = true;
            }
        }

        return $keys;
    }

    /** @return list<ItemRecord> */
    public function recordsOf(QuizTheme $theme): array
    {
        return $this->records($theme);
    }

    /** @return list<ItemRecord> */
    private function records(QuizTheme $theme): array
    {
        $id = (int) $theme->getId();
        // a new import changes importedAt, so a long-running worker never plays with outdated data
        $version = $theme->getImportedAt()->format('U.u');

        if (!isset($this->recordsCache[$id]) || $this->recordsCache[$id]['version'] !== $version) {
            $this->recordsCache[$id] = ['version' => $version, 'records' => $this->store->itemRecords($theme)];
        }

        return $this->recordsCache[$id]['records'];
    }
}
