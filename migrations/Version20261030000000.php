<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Spaced repetition: a player's progress per item and mode. */
final class Version20261030000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quiz bot: quiz_item_progress (Leitner boxes per player, theme, mode and item)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE quiz_item_progress (
                id INT AUTO_INCREMENT NOT NULL,
                player_id INT NOT NULL,
                theme_id INT NOT NULL,
                mode_slug VARCHAR(32) NOT NULL,
                item_key VARCHAR(40) NOT NULL,
                box INT NOT NULL,
                correct INT NOT NULL,
                wrong INT NOT NULL,
                due_at DATETIME NOT NULL,
                INDEX IDX_64B5E89C59027487 (theme_id),
                UNIQUE INDEX uniq_quiz_item_progress (player_id, theme_id, mode_slug, item_key),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE quiz_item_progress ADD CONSTRAINT FK_64B5E89C99E6F5DF FOREIGN KEY (player_id) REFERENCES quiz_player (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_item_progress ADD CONSTRAINT FK_64B5E89C59027487 FOREIGN KEY (theme_id) REFERENCES quiz_theme (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quiz_item_progress');
    }
}
