<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Rounds count finished questions separately from answers (a "list" question has many answers), and know their kind. */
final class Version20261030000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quiz bot: quiz_round.questions_done and quiz_round.list_mode';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_round ADD questions_done INT DEFAULT 0 NOT NULL, ADD list_mode TINYINT(1) DEFAULT 0 NOT NULL');
        // rounds that are already in progress: every answer so far was a question
        $this->addSql('UPDATE quiz_round SET questions_done = correct_count + wrong_count');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_round DROP questions_done, DROP list_mode');
    }
}
