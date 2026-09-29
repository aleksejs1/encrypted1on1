<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds anketas.reminderMeetingDay (GitHub issue #167, see Anketa::$reminderMeetingDay):
 * the meeting day the last reminder was for, so a reminder is due again only when the
 * meeting moves to another day. Backfilled for every already-reminded anketa with the day
 * the old day-before-only code reminded for: the meeting's own day when reminderSentAt
 * falls on it or the day before (a batch that ran past midnight stamped the meeting day
 * itself), otherwise the day after reminderSentAt (the meeting has moved since). So
 * nothing is reminded twice across the upgrade and a moved meeting is still due. Not
 * exact: a meeting moved by one day after a past-midnight stamp can't be told apart
 * from an unmoved one, and is recorded as reminded for its new day. Assumes the old
 * stamps are UTC, PHP's default timezone in the shipped images (docs/deployment.md, `TZ`). The reminder index drops
 * reminderSentAt, which the reminder query no longer filters on.
 *
 * down()'s temp-table recreate (rather than a bare DROP COLUMN) matches every other
 * column-removal migration in this project's history — generated via
 * `doctrine:migrations:diff`, not hand-written.
 */
final class Version20260929150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anketas.reminderMeetingDay, backfilled for already-reminded anketas';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketas ADD COLUMN reminderMeetingDay DATE DEFAULT NULL');
        $this->addSql('UPDATE anketas SET reminderMeetingDay = CASE WHEN DATE(reminderSentAt) BETWEEN DATE(meetingDate, \'-1 day\') AND DATE(meetingDate) THEN DATE(meetingDate) ELSE DATE(reminderSentAt, \'+1 day\') END WHERE reminderSentAt IS NOT NULL');
        $this->addSql('DROP INDEX idx_anketas_archived_reminder_meeting_date');
        $this->addSql('CREATE INDEX idx_anketas_archived_meeting_date ON anketas (archivedAt, meetingDate)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__anketas AS SELECT id, meetingDate, employeeSealedKey, managerSealedKey, employeeSealedKeyUpdatedAt, managerSealedKeyUpdatedAt, employeeBlob, employeePublishedAt, employeeBlobVersion, managerBlob, managerPublishedAt, managerBlobVersion, archivedAt, reminderSentAt, missed, periodicityDays, commentsBlob, commentsVersion, outcomesBlob, outcomesVersion, goalCheckpointsBlob, goalCheckpointsVersion, discussedBlob, discussedVersion, createdAt, formVersion, templateKey, oneOff, employee_id, manager_id, company_id, customTemplateVersion_id FROM anketas');
        $this->addSql('DROP TABLE anketas');
        $this->addSql('CREATE TABLE anketas (id VARCHAR(36) NOT NULL, meetingDate DATETIME NOT NULL, employeeSealedKey CLOB NOT NULL, managerSealedKey CLOB NOT NULL, employeeSealedKeyUpdatedAt DATETIME NOT NULL, managerSealedKeyUpdatedAt DATETIME NOT NULL, employeeBlob CLOB DEFAULT NULL, employeePublishedAt DATETIME DEFAULT NULL, employeeBlobVersion INTEGER NOT NULL, managerBlob CLOB DEFAULT NULL, managerPublishedAt DATETIME DEFAULT NULL, managerBlobVersion INTEGER NOT NULL, archivedAt DATETIME DEFAULT NULL, reminderSentAt DATETIME DEFAULT NULL, missed BOOLEAN NOT NULL, periodicityDays INTEGER DEFAULT NULL, commentsBlob CLOB DEFAULT NULL, commentsVersion INTEGER NOT NULL, outcomesBlob CLOB DEFAULT NULL, outcomesVersion INTEGER NOT NULL, goalCheckpointsBlob CLOB DEFAULT NULL, goalCheckpointsVersion INTEGER NOT NULL, discussedBlob CLOB DEFAULT NULL, discussedVersion INTEGER DEFAULT 0 NOT NULL, createdAt DATETIME NOT NULL, formVersion INTEGER NOT NULL, templateKey VARCHAR(40) DEFAULT \'regular\' NOT NULL, oneOff BOOLEAN DEFAULT 0 NOT NULL, employee_id VARCHAR(36) NOT NULL, manager_id VARCHAR(36) NOT NULL, company_id VARCHAR(36) NOT NULL, customTemplateVersion_id VARCHAR(36) DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_865B0D848C03F15C FOREIGN KEY (employee_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_865B0D84783E3463 FOREIGN KEY (manager_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_865B0D84979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_865B0D84E3829923 FOREIGN KEY (customTemplateVersion_id) REFERENCES custom_template_versions (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO anketas (id, meetingDate, employeeSealedKey, managerSealedKey, employeeSealedKeyUpdatedAt, managerSealedKeyUpdatedAt, employeeBlob, employeePublishedAt, employeeBlobVersion, managerBlob, managerPublishedAt, managerBlobVersion, archivedAt, reminderSentAt, missed, periodicityDays, commentsBlob, commentsVersion, outcomesBlob, outcomesVersion, goalCheckpointsBlob, goalCheckpointsVersion, discussedBlob, discussedVersion, createdAt, formVersion, templateKey, oneOff, employee_id, manager_id, company_id, customTemplateVersion_id) SELECT id, meetingDate, employeeSealedKey, managerSealedKey, employeeSealedKeyUpdatedAt, managerSealedKeyUpdatedAt, employeeBlob, employeePublishedAt, employeeBlobVersion, managerBlob, managerPublishedAt, managerBlobVersion, archivedAt, reminderSentAt, missed, periodicityDays, commentsBlob, commentsVersion, outcomesBlob, outcomesVersion, goalCheckpointsBlob, goalCheckpointsVersion, discussedBlob, discussedVersion, createdAt, formVersion, templateKey, oneOff, employee_id, manager_id, company_id, customTemplateVersion_id FROM __temp__anketas');
        $this->addSql('DROP TABLE __temp__anketas');
        $this->addSql('CREATE INDEX idx_anketas_employee_manager_meeting_date ON anketas (employee_id, manager_id, meetingDate)');
        $this->addSql('CREATE INDEX IDX_865B0D848C03F15C ON anketas (employee_id)');
        $this->addSql('CREATE INDEX IDX_865B0D84783E3463 ON anketas (manager_id)');
        $this->addSql('CREATE INDEX IDX_865B0D84979B1AD6 ON anketas (company_id)');
        $this->addSql('CREATE INDEX IDX_865B0D84E3829923 ON anketas (customTemplateVersion_id)');
        $this->addSql('CREATE INDEX idx_anketas_archived_reminder_meeting_date ON anketas (archivedAt, reminderSentAt, meetingDate)');
    }
}
