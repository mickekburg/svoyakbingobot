<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The number of answer options becomes a global player setting (/settings). */
final class Version20261102000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quiz bot: quiz_player.options_count';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_player ADD options_count INT DEFAULT 4 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_player DROP options_count');
    }
}
