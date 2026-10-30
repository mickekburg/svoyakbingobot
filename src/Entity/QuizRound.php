<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One tour of the game ("round"). Named quiz_round on purpose: "session" collides with Symfony/PHP sessions.
 *
 * Lifecycle: choose_mode -> await_options -> await_questions -> playing -> finished.
 */
#[ORM\Entity]
#[ORM\Table(name: 'quiz_round')]
#[ORM\Index(name: 'idx_quiz_round_player_state', columns: ['player_id', 'state'])]
#[ORM\Index(name: 'idx_quiz_round_state_activity', columns: ['state', 'last_activity_at'])]
class QuizRound
{
    public const STATE_CHOOSE_MODE = 'choose_mode';
    public const STATE_AWAIT_OPTIONS = 'await_options';
    public const STATE_AWAIT_QUESTIONS = 'await_questions';
    public const STATE_PLAYING = 'playing';
    public const STATE_FINISHED = 'finished';

    public const FINISH_COMPLETED = 'completed';
    public const FINISH_EARLY = 'early';
    public const FINISH_TIMEOUT = 'timeout';
    public const FINISH_RESTART = 'restart';
    public const FINISH_NO_DATA = 'no_data';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QuizPlayer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private QuizPlayer $player;

    #[ORM\ManyToOne(targetEntity: QuizTheme::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private QuizTheme $theme;

    /** Stored as a plain string (not an FK) so removing a mode from a config cannot break old rounds. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $modeSlug = null;

    #[ORM\Column(length: 24)]
    private string $state = self::STATE_CHOOSE_MODE;

    #[ORM\Column(nullable: true)]
    private ?int $optionsCount = null;

    #[ORM\Column(nullable: true)]
    private ?int $questionsTotal = null;

    #[ORM\Column]
    private int $correctCount = 0;

    #[ORM\Column]
    private int $wrongCount = 0;

    /** Questions that are over. For ordinary modes it equals the answers; a "list" question has many answers. */
    #[ORM\Column(options: ['default' => 0])]
    private int $questionsDone = 0;

    /** The mode asks to name elements: correct/wrong count named elements, not questions. */
    #[ORM\Column(options: ['default' => false])]
    private bool $listMode = false;

    /** 1-based number of the question currently on screen; 0 before the first question. */
    #[ORM\Column]
    private int $currentNumber = 0;

    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $usedItemIds = [];

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $currentQuestion = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastActivityAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $finishReason = null;

    public function __construct(QuizPlayer $player, QuizTheme $theme)
    {
        $this->player = $player;
        $this->theme = $theme;
        $this->createdAt = new \DateTimeImmutable();
        $this->lastActivityAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlayer(): QuizPlayer
    {
        return $this->player;
    }

    public function getTheme(): QuizTheme
    {
        return $this->theme;
    }

    public function getModeSlug(): ?string
    {
        return $this->modeSlug;
    }

    public function setModeSlug(string $modeSlug): void
    {
        $this->modeSlug = $modeSlug;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): void
    {
        $this->state = $state;
    }

    public function isFinished(): bool
    {
        return $this->state === self::STATE_FINISHED;
    }

    public function getOptionsCount(): ?int
    {
        return $this->optionsCount;
    }

    public function setOptionsCount(int $optionsCount): void
    {
        $this->optionsCount = $optionsCount;
    }

    public function getQuestionsTotal(): ?int
    {
        return $this->questionsTotal;
    }

    public function setQuestionsTotal(int $questionsTotal): void
    {
        $this->questionsTotal = $questionsTotal;
    }

    public function getCorrectCount(): int
    {
        return $this->correctCount;
    }

    public function getWrongCount(): int
    {
        return $this->wrongCount;
    }

    /** Questions that are over (answered, or closed with "enough" in a list mode). */
    public function getAnsweredCount(): int
    {
        return $this->questionsDone;
    }

    public function completeQuestion(): void
    {
        ++$this->questionsDone;
    }

    public function isListMode(): bool
    {
        return $this->listMode;
    }

    public function setListMode(bool $listMode): void
    {
        $this->listMode = $listMode;
    }

    public function registerAnswer(bool $correct): void
    {
        $correct ? $this->correctCount++ : $this->wrongCount++;
    }

    public function getCurrentNumber(): int
    {
        return $this->currentNumber;
    }

    public function setCurrentNumber(int $currentNumber): void
    {
        $this->currentNumber = $currentNumber;
    }

    /** @return list<int> */
    public function getUsedItemIds(): array
    {
        return $this->usedItemIds;
    }

    public function addUsedItemId(int $itemId): void
    {
        $this->usedItemIds[] = $itemId;
    }

    /** @return array<string, mixed>|null */
    public function getCurrentQuestion(): ?array
    {
        return $this->currentQuestion;
    }

    /** @param array<string, mixed>|null $currentQuestion */
    public function setCurrentQuestion(?array $currentQuestion): void
    {
        $this->currentQuestion = $currentQuestion;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function markStarted(): void
    {
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getLastActivityAt(): \DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function touch(): void
    {
        $this->lastActivityAt = new \DateTimeImmutable();
    }

    public function getFinishReason(): ?string
    {
        return $this->finishReason;
    }

    /** @param \DateTimeImmutable|null $at moment to treat as the end (idle rounds end at their last activity) */
    public function finish(string $reason, ?\DateTimeImmutable $at = null): void
    {
        $this->state = self::STATE_FINISHED;
        $this->finishReason = $reason;
        $this->finishedAt = $at ?? new \DateTimeImmutable();
        $this->currentQuestion = null;
    }

    /** Training time in seconds (0 if the round never started). */
    public function getDurationSeconds(): int
    {
        if ($this->startedAt === null) {
            return 0;
        }
        $end = $this->finishedAt ?? new \DateTimeImmutable();

        return max(0, $end->getTimestamp() - $this->startedAt->getTimestamp());
    }
}
