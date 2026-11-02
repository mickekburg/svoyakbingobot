<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'quiz_player')]
#[ORM\UniqueConstraint(name: 'uniq_quiz_player_tg', columns: ['telegram_user_id'])]
class QuizPlayer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** BIGINT: Doctrine hands it over as a numeric string. */
    #[ORM\Column(type: Types::BIGINT)]
    private string $telegramUserId;

    #[ORM\Column(type: Types::BIGINT)]
    private string $chatId;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Global setting: true = mistakes and due repetitions first, false = random questions. */
    #[ORM\Column(options: ['default' => true])]
    private bool $smartSelection = true;

    /** Global setting: true = rows flagged only_list are not asked in choice questions, false = every row. */
    #[ORM\Column(options: ['default' => true])]
    private bool $bingoOnly = true;

    /** Global setting: answer options per question (2..10); 0 = no options, the player types the answer. */
    #[ORM\Column(options: ['default' => 4])]
    private int $optionsCount = 4;

    public function __construct(int|string $telegramUserId, int|string $chatId)
    {
        $this->telegramUserId = (string) $telegramUserId;
        $this->chatId = (string) $chatId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTelegramUserId(): string
    {
        return $this->telegramUserId;
    }

    public function getChatId(): string
    {
        return $this->chatId;
    }

    public function isSmartSelection(): bool
    {
        return $this->smartSelection;
    }

    public function setSmartSelection(bool $smartSelection): void
    {
        $this->smartSelection = $smartSelection;
    }

    public function isBingoOnly(): bool
    {
        return $this->bingoOnly;
    }

    public function setBingoOnly(bool $bingoOnly): void
    {
        $this->bingoOnly = $bingoOnly;
    }

    public function getOptionsCount(): int
    {
        return $this->optionsCount;
    }

    public function setOptionsCount(int $optionsCount): void
    {
        $this->optionsCount = $optionsCount;
    }

    public function touch(int|string $chatId, ?string $username, ?string $firstName): void
    {
        $this->chatId = (string) $chatId;
        $this->username = $username;
        $this->firstName = $firstName;
    }
}
