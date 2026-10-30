<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Per-mode numbers computed by the import (see QuestionGenerator::analyze). */
#[ORM\Entity]
#[ORM\Table(name: 'quiz_mode_stat')]
#[ORM\UniqueConstraint(name: 'uniq_quiz_mode_stat', columns: ['theme_id', 'mode_slug'])]
class QuizModeStat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QuizTheme::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private QuizTheme $theme;

    #[ORM\Column(length: 32)]
    private string $modeSlug;

    #[ORM\Column]
    private int $maxOptions;

    #[ORM\Column]
    private int $eligibleItems;

    public function __construct(QuizTheme $theme, string $modeSlug, int $maxOptions, int $eligibleItems)
    {
        $this->theme = $theme;
        $this->modeSlug = $modeSlug;
        $this->maxOptions = $maxOptions;
        $this->eligibleItems = $eligibleItems;
    }

    public function getTheme(): QuizTheme
    {
        return $this->theme;
    }

    public function getModeSlug(): string
    {
        return $this->modeSlug;
    }

    public function getMaxOptions(): int
    {
        return $this->maxOptions;
    }

    public function getEligibleItems(): int
    {
        return $this->eligibleItems;
    }
}
