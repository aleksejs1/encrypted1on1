<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds anketas.templateKey (private/anketa-meeting-templates-proposal.md, not tracked in
 * git) — which built-in question-set template an anketa uses. DEFAULT 'regular' backfills
 * every pre-existing row for free; every anketa created before this migration behaves
 * exactly as it did before.
 *
 * down()'s temp-table recreate (rather than a bare DROP COLUMN) matches every other
 * column-removal migration in this project's history — generated via
 * `doctrine:migrations:diff`, not hand-written, to get the real column/constraint/index
 * list exactly right.
 */
final class Version20260920163822 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Add anketas.templateKey, defaulting existing and new rows to 'regular'";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE anketas ADD COLUMN templateKey VARCHAR(40) DEFAULT 'regular' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__anketas AS SELECT id, meetingDate, employeeSealedKey, managerSealedKey, employeeSealedKeyUpdatedAt, managerSealedKeyUpdatedAt, employeeBlob, employeePublishedAt, employeeBlobVersion, managerBlob, managerPublishedAt, managerBlobVersion, archivedAt, reminderSentAt, missed, periodicityDays, commentsBlob, commentsVersion, outcomesBlob, outcomesVersion, goalCheckpointsBlob, goalCheckpointsVersion, createdAt, formVersion, employee_id, manager_id, company_id FROM anketas');
        $this->addSql('DROP TABLE anketas');
        $this->addSql('CREATE TABLE anketas (id VARCHAR(36) NOT NULL, meetingDate DATETIME NOT NULL, employeeSealedKey CLOB NOT NULL, managerSealedKey CLOB NOT NULL, employeeSealedKeyUpdatedAt DATETIME NOT NULL, managerSealedKeyUpdatedAt DATETIME NOT NULL, employeeBlob CLOB DEFAULT NULL, employeePublishedAt DATETIME DEFAULT NULL, employeeBlobVersion INTEGER NOT NULL, managerBlob CLOB DEFAULT NULL, managerPublishedAt DATETIME DEFAULT NULL, managerBlobVersion INTEGER NOT NULL, archivedAt DATETIME DEFAULT NULL, reminderSentAt DATETIME DEFAULT NULL, missed BOOLEAN NOT NULL, periodicityDays INTEGER DEFAULT NULL, commentsBlob CLOB DEFAULT NULL, commentsVersion INTEGER NOT NULL, outcomesBlob CLOB DEFAULT NULL, outcomesVersion INTEGER NOT NULL, goalCheckpointsBlob CLOB DEFAULT NULL, goalCheckpointsVersion INTEGER NOT NULL, createdAt DATETIME NOT NULL, formVersion INTEGER NOT NULL, employee_id VARCHAR(36) NOT NULL, manager_id VARCHAR(36) NOT NULL, company_id VARCHAR(36) NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_865B0D848C03F15C FOREIGN KEY (employee_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_865B0D84783E3463 FOREIGN KEY (manager_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_865B0D84979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO anketas (id, meetingDate, employeeSealedKey, managerSealedKey, employeeSealedKeyUpdatedAt, managerSealedKeyUpdatedAt, employeeBlob, employeePublishedAt, employeeBlobVersion, managerBlob, managerPublishedAt, managerBlobVersion, archivedAt, reminderSentAt, missed, periodicityDays, commentsBlob, commentsVersion, outcomesBlob, outcomesVersion, goalCheckpointsBlob, goalCheckpointsVersion, createdAt, formVersion, employee_id, manager_id, company_id) SELECT id, meetingDate, employeeSealedKey, managerSealedKey, employeeSealedKeyUpdatedAt, managerSealedKeyUpdatedAt, employeeBlob, employeePublishedAt, employeeBlobVersion, managerBlob, managerPublishedAt, managerBlobVersion, archivedAt, reminderSentAt, missed, periodicityDays, commentsBlob, commentsVersion, outcomesBlob, outcomesVersion, goalCheckpointsBlob, goalCheckpointsVersion, createdAt, formVersion, employee_id, manager_id, company_id FROM __temp__anketas');
        $this->addSql('DROP TABLE __temp__anketas');
        $this->addSql('CREATE INDEX IDX_865B0D848C03F15C ON anketas (employee_id)');
        $this->addSql('CREATE INDEX IDX_865B0D84783E3463 ON anketas (manager_id)');
        $this->addSql('CREATE INDEX IDX_865B0D84979B1AD6 ON anketas (company_id)');
        $this->addSql('CREATE INDEX idx_anketas_employee_manager_meeting_date ON anketas (employee_id, manager_id, meetingDate)');
        $this->addSql('CREATE INDEX idx_anketas_archived_reminder_meeting_date ON anketas (archivedAt, reminderSentAt, meetingDate)');
    }
}
