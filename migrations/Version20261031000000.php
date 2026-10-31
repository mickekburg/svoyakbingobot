<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The player chooses before a tour whether questions are picked smartly (mistakes first) or at random. */
final class Version20261031000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quiz bot: quiz_round.smart_selection';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_round ADD smart_selection TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_round DROP smart_selection');
    }
}
