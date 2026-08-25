<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds anketas.formVersion — the question-set template version an anketa was created
 * against (Anketa::CURRENT_FORM_VERSION, frontend/src/anketa/questions.ts). Every
 * existing row predates this concept and used the original (6-option "feelings")
 * question set, so they're backfilled to 1; new anketas get the current version
 * (2, the 12-option set) via the entity constructor, not this column's default.
 */
final class Version20260825140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anketas.formVersion, defaulting to 1 (the pre-versioning question set) for every existing row';
    }

    public function up(Schema $schema): void
    {
        // A literal DEFAULT is required here — same lesson displayName's/isDemo's/
        // isPlatformAdmin's own migrations already hit: SQLite's ADD COLUMN rejects
        // NOT NULL with no default against a populated table.
        $this->addSql('ALTER TABLE anketas ADD COLUMN formVersion INTEGER NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__anketas AS
            SELECT id, meetingDate, employeeSealedKey, managerSealedKey, employeeSealedKeyUpdatedAt,
              managerSealedKeyUpdatedAt, employeeBlob, employeePublishedAt, managerBlob, managerPublishedAt,
              archivedAt, reminderSentAt, missed, periodicityDays, commentsBlob, commentsVersion, outcomesBlob,
              outcomesVersion, goalCheckpointsBlob, goalCheckpointsVersion, createdAt, employee_id, manager_id
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
              outcomesVersion, goalCheckpointsBlob, goalCheckpointsVersion, createdAt, employee_id, manager_id
            )
            SELECT id, meetingDate, employeeSealedKey, managerSealedKey, employeeSealedKeyUpdatedAt,
              managerSealedKeyUpdatedAt, employeeBlob, employeePublishedAt, managerBlob, managerPublishedAt,
              archivedAt, reminderSentAt, missed, periodicityDays, commentsBlob, commentsVersion, outcomesBlob,
              outcomesVersion, goalCheckpointsBlob, goalCheckpointsVersion, createdAt, employee_id, manager_id
            FROM __temp__anketas
        SQL);
        $this->addSql('DROP TABLE __temp__anketas');
        $this->addSql('CREATE INDEX IDX_865B0D848C03F15C ON anketas (employee_id)');
        $this->addSql('CREATE INDEX IDX_865B0D84783E3463 ON anketas (manager_id)');
        $this->addSql('CREATE INDEX idx_anketas_employee_manager_meeting_date ON anketas (employee_id, manager_id, meetingDate)');
        $this->addSql('CREATE INDEX idx_anketas_archived_reminder_meeting_date ON anketas (archivedAt, reminderSentAt, meetingDate)');
    }
}
