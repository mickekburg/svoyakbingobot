<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The player chooses before a tour whether only "bingo" questions are shown or all of them (ignoring only_list). */
final class Version20261101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quiz bot: quiz_round.bingo_only';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_round ADD bingo_only TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_round DROP bingo_only');
    }
}
