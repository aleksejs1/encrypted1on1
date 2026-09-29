<?php

declare(strict_types=1);

namespace App\Migrations\MySQL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written, MySQL-native counterpart to the SQLite Version20260929150000 migration —
 * adds anketas.reminderMeetingDay (GitHub issue #167), backfilled for already-reminded
 * anketas with the day they were reminded for (see the SQLite migration's docblock), and drops reminderSentAt from the reminder index.
 */
final class Version20260929150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anketas.reminderMeetingDay, backfilled for already-reminded anketas';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketas ADD reminderMeetingDay DATE DEFAULT NULL');
        $this->addSql('UPDATE anketas SET reminderMeetingDay = CASE WHEN DATE(reminderSentAt) BETWEEN DATE(meetingDate) - INTERVAL 1 DAY AND DATE(meetingDate) THEN DATE(meetingDate) ELSE DATE(reminderSentAt) + INTERVAL 1 DAY END WHERE reminderSentAt IS NOT NULL');
        $this->addSql('DROP INDEX idx_anketas_archived_reminder_meeting_date ON anketas');
        $this->addSql('CREATE INDEX idx_anketas_archived_meeting_date ON anketas (archivedAt, meetingDate)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_anketas_archived_meeting_date ON anketas');
        $this->addSql('CREATE INDEX idx_anketas_archived_reminder_meeting_date ON anketas (archivedAt, reminderSentAt, meetingDate)');
        $this->addSql('ALTER TABLE anketas DROP reminderMeetingDay');
    }
}
