<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema (MySQL 8 / MariaDB 10.5+, InnoDB, utf8mb4).
 * Written to match the ORM mapping of src/Entity exactly; after `composer install` check it with
 *   php bin/console doctrine:schema:validate
 * Index/foreign key names follow Doctrine's own naming, so a later migrations:diff stays quiet.
 */
final class Version20261024000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quiz bot: themes, items, mode statistics, Telegram file cache, players, rounds';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE quiz_theme (
                id INT AUTO_INCREMENT NOT NULL,
                slug VARCHAR(32) NOT NULL,
                title VARCHAR(255) NOT NULL,
                config_hash VARCHAR(64) NOT NULL,
                imported_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_quiz_theme_slug (slug),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE quiz_item (
                id INT AUTO_INCREMENT NOT NULL,
                theme_id INT NOT NULL,
                row_num INT NOT NULL,
                attrs JSON NOT NULL,
                UNIQUE INDEX uniq_quiz_item_theme_row (theme_id, row_num),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE quiz_mode_stat (
                id INT AUTO_INCREMENT NOT NULL,
                theme_id INT NOT NULL,
                mode_slug VARCHAR(32) NOT NULL,
                max_options INT NOT NULL,
                eligible_items INT NOT NULL,
                UNIQUE INDEX uniq_quiz_mode_stat (theme_id, mode_slug),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE quiz_telegram_file (
                id INT AUTO_INCREMENT NOT NULL,
                theme_id INT NOT NULL,
                image_path VARCHAR(255) NOT NULL,
                sha1 VARCHAR(40) NOT NULL,
                file_id VARCHAR(255) NOT NULL,
                UNIQUE INDEX uniq_quiz_telegram_file (theme_id, image_path),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE quiz_player (
                id INT AUTO_INCREMENT NOT NULL,
                telegram_user_id BIGINT NOT NULL,
                chat_id BIGINT NOT NULL,
                username VARCHAR(64) DEFAULT NULL,
                first_name VARCHAR(128) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_quiz_player_tg (telegram_user_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE quiz_round (
                id INT AUTO_INCREMENT NOT NULL,
                player_id INT NOT NULL,
                theme_id INT NOT NULL,
                mode_slug VARCHAR(32) DEFAULT NULL,
                state VARCHAR(24) NOT NULL,
                options_count INT DEFAULT NULL,
                questions_total INT DEFAULT NULL,
                correct_count INT NOT NULL,
                wrong_count INT NOT NULL,
                current_number INT NOT NULL,
                used_item_ids JSON NOT NULL,
                current_question JSON DEFAULT NULL,
                started_at DATETIME DEFAULT NULL,
                finished_at DATETIME DEFAULT NULL,
                last_activity_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                finish_reason VARCHAR(16) DEFAULT NULL,
                INDEX IDX_399D93B459027487 (theme_id),
                INDEX idx_quiz_round_player_state (player_id, state),
                INDEX idx_quiz_round_state_activity (state, last_activity_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE quiz_item ADD CONSTRAINT FK_D5EAD94559027487 FOREIGN KEY (theme_id) REFERENCES quiz_theme (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_mode_stat ADD CONSTRAINT FK_D8F13F9459027487 FOREIGN KEY (theme_id) REFERENCES quiz_theme (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_telegram_file ADD CONSTRAINT FK_DA35C21159027487 FOREIGN KEY (theme_id) REFERENCES quiz_theme (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_round ADD CONSTRAINT FK_399D93B499E6F5DF FOREIGN KEY (player_id) REFERENCES quiz_player (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_round ADD CONSTRAINT FK_399D93B459027487 FOREIGN KEY (theme_id) REFERENCES quiz_theme (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_round DROP FOREIGN KEY FK_399D93B499E6F5DF');
        $this->addSql('ALTER TABLE quiz_round DROP FOREIGN KEY FK_399D93B459027487');
        $this->addSql('ALTER TABLE quiz_telegram_file DROP FOREIGN KEY FK_DA35C21159027487');
        $this->addSql('ALTER TABLE quiz_mode_stat DROP FOREIGN KEY FK_D8F13F9459027487');
        $this->addSql('ALTER TABLE quiz_item DROP FOREIGN KEY FK_D5EAD94559027487');
        $this->addSql('DROP TABLE quiz_round');
        $this->addSql('DROP TABLE quiz_player');
        $this->addSql('DROP TABLE quiz_telegram_file');
        $this->addSql('DROP TABLE quiz_mode_stat');
        $this->addSql('DROP TABLE quiz_item');
        $this->addSql('DROP TABLE quiz_theme');
    }
}
