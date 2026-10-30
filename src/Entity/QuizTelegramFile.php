<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Cache of Telegram file_id values so a picture is uploaded only once. */
#[ORM\Entity]
#[ORM\Table(name: 'quiz_telegram_file')]
#[ORM\UniqueConstraint(name: 'uniq_quiz_telegram_file', columns: ['theme_id', 'image_path'])]
class QuizTelegramFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QuizTheme::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private QuizTheme $theme;

    #[ORM\Column(length: 255)]
    private string $imagePath;

    #[ORM\Column(length: 40)]
    private string $sha1;

    #[ORM\Column(length: 255)]
    private string $fileId;

    public function __construct(QuizTheme $theme, string $imagePath, string $sha1, string $fileId)
    {
        $this->theme = $theme;
        $this->imagePath = $imagePath;
        $this->sha1 = $sha1;
        $this->fileId = $fileId;
    }

    public function getSha1(): string
    {
        return $this->sha1;
    }

    public function getFileId(): string
    {
        return $this->fileId;
    }

    public function update(string $sha1, string $fileId): void
    {
        $this->sha1 = $sha1;
        $this->fileId = $fileId;
    }
}
