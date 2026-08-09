<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260809042754 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE goals (id VARCHAR(36) NOT NULL, goalUuid VARCHAR(36) NOT NULL, title VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, targetDate DATE DEFAULT NULL, status VARCHAR(20) NOT NULL, createdAt DATETIME NOT NULL, anketa_id VARCHAR(36) NOT NULL, author_id VARCHAR(36) NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_C7241E2FAF307F7D FOREIGN KEY (anketa_id) REFERENCES anketas (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_C7241E2FF675F31B FOREIGN KEY (author_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_C7241E2FAF307F7D ON goals (anketa_id)');
        $this->addSql('CREATE INDEX IDX_C7241E2FF675F31B ON goals (author_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__anketas AS SELECT id, meetingDate, employeeSealedKey, managerSealedKey, employeeBlob, employeePublishedAt, managerBlob, managerPublishedAt, archivedAt, createdAt, employee_id, manager_id, commentsBlob, commentsVersion, outcomesBlob, outcomesVersion FROM anketas');
        $this->addSql('DROP TABLE anketas');
        $this->addSql('CREATE TABLE anketas (id VARCHAR(36) NOT NULL, meetingDate DATETIME NOT NULL, employeeSealedKey CLOB NOT NULL, managerSealedKey CLOB NOT NULL, employeeBlob CLOB DEFAULT NULL, employeePublishedAt DATETIME DEFAULT NULL, managerBlob CLOB DEFAULT NULL, managerPublishedAt DATETIME DEFAULT NULL, archivedAt DATETIME DEFAULT NULL, createdAt DATETIME NOT NULL, employee_id VARCHAR(36) NOT NULL, manager_id VARCHAR(36) NOT NULL, commentsBlob CLOB DEFAULT NULL, commentsVersion INTEGER NOT NULL, outcomesBlob CLOB DEFAULT NULL, outcomesVersion INTEGER NOT NULL, goalCheckpointsBlob CLOB DEFAULT NULL, goalCheckpointsVersion INTEGER DEFAULT 0 NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_865B0D848C03F15C FOREIGN KEY (employee_id) REFERENCES users (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_865B0D84783E3463 FOREIGN KEY (manager_id) REFERENCES users (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO anketas (id, meetingDate, employeeSealedKey, managerSealedKey, employeeBlob, employeePublishedAt, managerBlob, managerPublishedAt, archivedAt, createdAt, employee_id, manager_id, commentsBlob, commentsVersion, outcomesBlob, outcomesVersion) SELECT id, meetingDate, employeeSealedKey, managerSealedKey, employeeBlob, employeePublishedAt, managerBlob, managerPublishedAt, archivedAt, createdAt, employee_id, manager_id, commentsBlob, commentsVersion, outcomesBlob, outcomesVersion FROM __temp__anketas');
        $this->addSql('DROP TABLE __temp__anketas');
        $this->addSql('CREATE INDEX IDX_865B0D848C03F15C ON anketas (employee_id)');
        $this->addSql('CREATE INDEX IDX_865B0D84783E3463 ON anketas (manager_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE goals');
        $this->addSql('CREATE TEMPORARY TABLE __temp__anketas AS SELECT id, meetingDate, employeeSealedKey, managerSealedKey, employeeBlob, employeePublishedAt, managerBlob, managerPublishedAt, archivedAt, commentsBlob, commentsVersion, outcomesBlob, outcomesVersion, createdAt, employee_id, manager_id FROM anketas');
        $this->addSql('DROP TABLE anketas');
        $this->addSql('CREATE TABLE anketas (id VARCHAR(36) NOT NULL, meetingDate DATETIME NOT NULL, employeeSealedKey CLOB NOT NULL, managerSealedKey CLOB NOT NULL, employeeBlob CLOB DEFAULT NULL, employeePublishedAt DATETIME DEFAULT NULL, managerBlob CLOB DEFAULT NULL, managerPublishedAt DATETIME DEFAULT NULL, archivedAt DATETIME DEFAULT NULL, commentsBlob CLOB DEFAULT NULL, commentsVersion INTEGER DEFAULT 0 NOT NULL, outcomesBlob CLOB DEFAULT NULL, outcomesVersion INTEGER DEFAULT 0 NOT NULL, createdAt DATETIME NOT NULL, employee_id VARCHAR(36) NOT NULL, manager_id VARCHAR(36) NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_865B0D848C03F15C FOREIGN KEY (employee_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_865B0D84783E3463 FOREIGN KEY (manager_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO anketas (id, meetingDate, employeeSealedKey, managerSealedKey, employeeBlob, employeePublishedAt, managerBlob, managerPublishedAt, archivedAt, commentsBlob, commentsVersion, outcomesBlob, outcomesVersion, createdAt, employee_id, manager_id) SELECT id, meetingDate, employeeSealedKey, managerSealedKey, employeeBlob, employeePublishedAt, managerBlob, managerPublishedAt, archivedAt, commentsBlob, commentsVersion, outcomesBlob, outcomesVersion, createdAt, employee_id, manager_id FROM __temp__anketas');
        $this->addSql('DROP TABLE __temp__anketas');
        $this->addSql('CREATE INDEX IDX_865B0D848C03F15C ON anketas (employee_id)');
        $this->addSql('CREATE INDEX IDX_865B0D84783E3463 ON anketas (manager_id)');
    }
}
