<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds anketas.followUpMeetingDay (GitHub issue #202, see Anketa::$followUpMeetingDay):
 * the meeting day the "did your 1:1 happen?" follow-up email was for. Not backfilled: a
 * meeting already past its day and still open when this ships gets its follow-up from
 * the first weekday run, if its day is within that run's three-day window, and none
 * otherwise.
 *
 * Generated via `doctrine:migrations:diff` and trimmed (the diff also re-emitted
 * unrelated column-default drift on companies/users). down()'s temp-table recreate
 * matches every other column-removal migration in this project's history.
 */
final class Version20261003170313 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anketas.followUpMeetingDay';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketas ADD COLUMN followUpMeetingDay DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__anketas AS SELECT id, meetingDate, employeeSealedKey, managerSealedKey, employeeSealedKeyUpdatedAt, managerSealedKeyUpdatedAt, employeeBlob, employeePublishedAt, employeeBlobVersion, managerBlob, managerPublishedAt, managerBlobVersion, archivedAt, reminderSentAt, reminderMeetingDay, missed, periodicityDays, commentsBlob, commentsVersion, outcomesBlob, outcomesVersion, goalCheckpointsBlob, goalCheckpointsVersion, discussedBlob, discussedVersion, createdAt, formVersion, templateKey, oneOff, employee_id, manager_id, company_id, customTemplateVersion_id FROM anketas');
        $this->addSql('DROP TABLE anketas');
        $this->addSql('CREATE TABLE anketas (id VARCHAR(36) NOT NULL, meetingDate DATETIME NOT NULL, employeeSealedKey CLOB NOT NULL, managerSealedKey CLOB NOT NULL, employeeSealedKeyUpdatedAt DATETIME NOT NULL, managerSealedKeyUpdatedAt DATETIME NOT NULL, employeeBlob CLOB DEFAULT NULL, employeePublishedAt DATETIME DEFAULT NULL, employeeBlobVersion INTEGER NOT NULL, managerBlob CLOB DEFAULT NULL, managerPublishedAt DATETIME DEFAULT NULL, managerBlobVersion INTEGER NOT NULL, archivedAt DATETIME DEFAULT NULL, reminderSentAt DATETIME DEFAULT NULL, reminderMeetingDay DATE DEFAULT NULL, missed BOOLEAN NOT NULL, periodicityDays INTEGER DEFAULT NULL, commentsBlob CLOB DEFAULT NULL, commentsVersion INTEGER NOT NULL, outcomesBlob CLOB DEFAULT NULL, outcomesVersion INTEGER NOT NULL, goalCheckpointsBlob CLOB DEFAULT NULL, goalCheckpointsVersion INTEGER NOT NULL, discussedBlob CLOB DEFAULT NULL, discussedVersion INTEGER DEFAULT 0 NOT NULL, createdAt DATETIME NOT NULL, formVersion INTEGER NOT NULL, templateKey VARCHAR(40) DEFAULT \'regular\' NOT NULL, oneOff BOOLEAN DEFAULT 0 NOT NULL, employee_id VARCHAR(36) NOT NULL, manager_id VARCHAR(36) NOT NULL, company_id VARCHAR(36) NOT NULL, customTemplateVersion_id VARCHAR(36) DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_865B0D848C03F15C FOREIGN KEY (employee_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_865B0D84783E3463 FOREIGN KEY (manager_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_865B0D84979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_865B0D84E3829923 FOREIGN KEY (customTemplateVersion_id) REFERENCES custom_template_versions (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO anketas (id, meetingDate, employeeSealedKey, managerSealedKey, employeeSealedKeyUpdatedAt, managerSealedKeyUpdatedAt, employeeBlob, employeePublishedAt, employeeBlobVersion, managerBlob, managerPublishedAt, managerBlobVersion, archivedAt, reminderSentAt, reminderMeetingDay, missed, periodicityDays, commentsBlob, commentsVersion, outcomesBlob, outcomesVersion, goalCheckpointsBlob, goalCheckpointsVersion, discussedBlob, discussedVersion, createdAt, formVersion, templateKey, oneOff, employee_id, manager_id, company_id, customTemplateVersion_id) SELECT id, meetingDate, employeeSealedKey, managerSealedKey, employeeSealedKeyUpdatedAt, managerSealedKeyUpdatedAt, employeeBlob, employeePublishedAt, employeeBlobVersion, managerBlob, managerPublishedAt, managerBlobVersion, archivedAt, reminderSentAt, reminderMeetingDay, missed, periodicityDays, commentsBlob, commentsVersion, outcomesBlob, outcomesVersion, goalCheckpointsBlob, goalCheckpointsVersion, discussedBlob, discussedVersion, createdAt, formVersion, templateKey, oneOff, employee_id, manager_id, company_id, customTemplateVersion_id FROM __temp__anketas');
        $this->addSql('DROP TABLE __temp__anketas');
        $this->addSql('CREATE INDEX idx_anketas_employee_manager_meeting_date ON anketas (employee_id, manager_id, meetingDate)');
        $this->addSql('CREATE INDEX idx_anketas_archived_meeting_date ON anketas (archivedAt, meetingDate)');
        $this->addSql('CREATE INDEX IDX_865B0D848C03F15C ON anketas (employee_id)');
        $this->addSql('CREATE INDEX IDX_865B0D84783E3463 ON anketas (manager_id)');
        $this->addSql('CREATE INDEX IDX_865B0D84979B1AD6 ON anketas (company_id)');
        $this->addSql('CREATE INDEX IDX_865B0D84E3829923 ON anketas (customTemplateVersion_id)');
    }
}
