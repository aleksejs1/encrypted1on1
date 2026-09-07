<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds anketas.employeeBlobVersion/managerBlobVersion — optimistic-concurrency guards for
 * Anketa::updateAnswers() (see docs/decisions/2026-09-07-editable-published-anketa-answers.md),
 * same shape as the existing commentsVersion/outcomesVersion/goalCheckpointsVersion. Every
 * existing row defaults to 0, identical to what a freshly published side already has.
 */
final class Version20260907034801 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anketas.employeeBlobVersion/managerBlobVersion, both defaulting to 0';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketas ADD COLUMN employeeBlobVersion INTEGER NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE anketas ADD COLUMN managerBlobVersion INTEGER NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__anketas AS
            SELECT id, meetingDate, employeeSealedKey, managerSealedKey, employeeSealedKeyUpdatedAt,
              managerSealedKeyUpdatedAt, employeeBlob, employeePublishedAt, managerBlob, managerPublishedAt,
              archivedAt, reminderSentAt, missed, periodicityDays, commentsBlob, commentsVersion, outcomesBlob,
              outcomesVersion, goalCheckpointsBlob, goalCheckpointsVersion, createdAt, formVersion, employee_id, manager_id
            FROM anketas
        SQL);
        $this->addSql('DROP TABLE anketas');
        $this->addSql(<<<'SQL'
            CREATE TABLE anketas (
              id VARCHAR(36) NOT NULL,
              meetingDate DATETIME NOT NULL,
              employeeSealedKey CLOB NOT NULL,
              managerSealedKey CLOB NOT NULL,
              employeeSealedKeyUpdatedAt DATETIME NOT NULL,
              managerSealedKeyUpdatedAt DATETIME NOT NULL,
              employeeBlob CLOB DEFAULT NULL,
              employeePublishedAt DATETIME DEFAULT NULL,
              managerBlob CLOB DEFAULT NULL,
              managerPublishedAt DATETIME DEFAULT NULL,
              archivedAt DATETIME DEFAULT NULL,
              reminderSentAt DATETIME DEFAULT NULL,
              missed BOOLEAN NOT NULL,
              periodicityDays INTEGER DEFAULT NULL,
              commentsBlob CLOB DEFAULT NULL,
              commentsVersion INTEGER NOT NULL,
              outcomesBlob CLOB DEFAULT NULL,
              outcomesVersion INTEGER NOT NULL,
              goalCheckpointsBlob CLOB DEFAULT NULL,
              goalCheckpointsVersion INTEGER NOT NULL,
              createdAt DATETIME NOT NULL,
              formVersion INTEGER NOT NULL,
              employee_id VARCHAR(36) NOT NULL,
              manager_id VARCHAR(36) NOT NULL,
              PRIMARY KEY (id),
              CONSTRAINT FK_865B0D848C03F15C FOREIGN KEY (employee_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_865B0D84783E3463 FOREIGN KEY (manager_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO anketas (
              id, meetingDate, employeeSealedKey, managerSealedKey, employeeSealedKeyUpdatedAt,
              managerSealedKeyUpdatedAt, employeeBlob, employeePublishedAt, managerBlob, managerPublishedAt,
              archivedAt, reminderSentAt, missed, periodicityDays, commentsBlob, commentsVersion, outcomesBlob,
              outcomesVersion, goalCheckpointsBlob, goalCheckpointsVersion, createdAt, formVersion, employee_id, manager_id
            )
            SELECT id, meetingDate, employeeSealedKey, managerSealedKey, employeeSealedKeyUpdatedAt,
              managerSealedKeyUpdatedAt, employeeBlob, employeePublishedAt, managerBlob, managerPublishedAt,
              archivedAt, reminderSentAt, missed, periodicityDays, commentsBlob, commentsVersion, outcomesBlob,
              outcomesVersion, goalCheckpointsBlob, goalCheckpointsVersion, createdAt, formVersion, employee_id, manager_id
            FROM __temp__anketas
        SQL);
        $this->addSql('DROP TABLE __temp__anketas');
        $this->addSql('CREATE INDEX IDX_865B0D848C03F15C ON anketas (employee_id)');
        $this->addSql('CREATE INDEX IDX_865B0D84783E3463 ON anketas (manager_id)');
        $this->addSql('CREATE INDEX idx_anketas_employee_manager_meeting_date ON anketas (employee_id, manager_id, meetingDate)');
        $this->addSql('CREATE INDEX idx_anketas_archived_reminder_meeting_date ON anketas (archivedAt, reminderSentAt, meetingDate)');
    }
}
