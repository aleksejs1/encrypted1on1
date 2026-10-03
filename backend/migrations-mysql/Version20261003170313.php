<?php

declare(strict_types=1);

namespace App\Migrations\MySQL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written, MySQL-native counterpart to the SQLite Version20261003170313 migration —
 * adds anketas.followUpMeetingDay (GitHub issue #202), the same nullable DATE column as
 * Version20260929150000's reminderMeetingDay. Nullable, so a populated table needs no
 * backfill.
 */
final class Version20261003170313 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anketas.followUpMeetingDay';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketas ADD followUpMeetingDay DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketas DROP followUpMeetingDay');
    }
}
