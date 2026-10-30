<?php

declare(strict_types=1);

namespace App\Entity;

use App\Quiz\ItemProgress;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A player's progress with one item in one mode (see App\Quiz\Leitner). The item is identified by ItemKey, not by id. */
#[ORM\Entity]
#[ORM\Table(name: 'quiz_item_progress')]
#[ORM\UniqueConstraint(name: 'uniq_quiz_item_progress', columns: ['player_id', 'theme_id', 'mode_slug', 'item_key'])]
class QuizItemProgress
{
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

    #[ORM\Column(length: 32)]
    private string $modeSlug;

    #[ORM\Column(length: 40)]
    private string $itemKey;

    #[ORM\Column]
    private int $box = 0;

    #[ORM\Column]
    private int $correct = 0;

    #[ORM\Column]
    private int $wrong = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $dueAt;

    public function __construct(QuizPlayer $player, QuizTheme $theme, string $modeSlug, ItemProgress $progress)
    {
        $this->player = $player;
        $this->theme = $theme;
        $this->modeSlug = $modeSlug;
        $this->itemKey = $progress->itemKey;
        $this->apply($progress);
    }

    public function apply(ItemProgress $progress): void
    {
        $this->box = $progress->box;
        $this->correct = $progress->correct;
        $this->wrong = $progress->wrong;
        $this->dueAt = $progress->dueAt;
    }

    public function toProgress(): ItemProgress
    {
        return new ItemProgress($this->itemKey, $this->box, $this->correct, $this->wrong, $this->dueAt);
    }

    public function getModeSlug(): string
    {
        return $this->modeSlug;
    }

    public function getItemKey(): string
    {
        return $this->itemKey;
    }
}
