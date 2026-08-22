<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two composite indexes on `anketas`, trimmed by hand from the auto-generated diff, which
 * also rebuilt `anketas`/`companies`/`users` wholesale over a cosmetic FK-clause phrasing
 * difference in Doctrine's own DDL generation (the same diff-tool noise
 * Version20260816071518 already had to filter out) — SQLite's `CREATE INDEX` needs no
 * table rebuild, so this migration only ever touches the two new indexes.
 */
final class Version20260822180835 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite indexes on anketas for the list/bulk participant query and the daily reminder-command query';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_anketas_employee_manager_meeting_date ON anketas (employee_id, manager_id, meetingDate)');
        $this->addSql('CREATE INDEX idx_anketas_archived_reminder_meeting_date ON anketas (archivedAt, reminderSentAt, meetingDate)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_anketas_employee_manager_meeting_date');
        $this->addSql('DROP INDEX idx_anketas_archived_reminder_meeting_date');
    }
}
