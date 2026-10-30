<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row of a theme's xlsx file. Identity = (theme, row number in the file); the number is Excel's own
 * (header = row 1, first data row = 2), so import errors and database rows point at the same line.
 */
#[ORM\Entity]
#[ORM\Table(name: 'quiz_item')]
#[ORM\UniqueConstraint(name: 'uniq_quiz_item_theme_row', columns: ['theme_id', 'row_num'])]
class QuizItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QuizTheme::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private QuizTheme $theme;

    #[ORM\Column]
    private int $rowNum;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $attrs;

    /** @param array<string, mixed> $attrs */
    public function __construct(QuizTheme $theme, int $rowNum, array $attrs)
    {
        $this->theme = $theme;
        $this->rowNum = $rowNum;
        $this->attrs = $attrs;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTheme(): QuizTheme
    {
        return $this->theme;
    }

    public function getRowNum(): int
    {
        return $this->rowNum;
    }

    /** @return array<string, mixed> */
    public function getAttrs(): array
    {
        return $this->attrs;
    }
}
