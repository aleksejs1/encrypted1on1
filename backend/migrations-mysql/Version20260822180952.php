<?php

declare(strict_types=1);

namespace App\Migrations\MySQL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two composite indexes on `anketas`, matching the SQLite equivalent
 * (Version20260822180835) — trimmed by hand from the auto-generated diff, which also
 * included an unrelated `users.isDemo`/`isPlatformAdmin` DEFAULT-clause change (pre-existing
 * drift, same kind of cosmetic diff-tool noise Version20260816071518 already had to filter
 * out; nothing to do with these indexes).
 */
final class Version20260822180952 extends AbstractMigration
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
        $this->addSql('DROP INDEX idx_anketas_employee_manager_meeting_date ON anketas');
        $this->addSql('DROP INDEX idx_anketas_archived_reminder_meeting_date ON anketas');
    }
}
