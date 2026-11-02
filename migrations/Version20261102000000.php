<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Selection (smart/random) and scope (bingo only/all) become the player's global settings (/settings). */
final class Version20261102000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quiz bot: quiz_player.smart_selection, quiz_player.bingo_only';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_player ADD smart_selection TINYINT(1) DEFAULT 1 NOT NULL, ADD bingo_only TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_player DROP smart_selection, DROP bingo_only');
    }
}
