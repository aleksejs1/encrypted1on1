<?php

declare(strict_types=1);

namespace App\Migrations\MySQL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260813123830 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE activation_tokens (id VARCHAR(36) NOT NULL, tokenHash VARCHAR(64) NOT NULL, email VARCHAR(255) NOT NULL, grantsAdmin TINYINT NOT NULL, expiresAt DATETIME NOT NULL, usedAt DATETIME DEFAULT NULL, createdAt DATETIME NOT NULL, UNIQUE INDEX UNIQ_C1DFC359E5C96920 (tokenHash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE anketas (id VARCHAR(36) NOT NULL, meetingDate DATETIME NOT NULL, employeeSealedKey LONGTEXT NOT NULL, managerSealedKey LONGTEXT NOT NULL, employeeSealedKeyUpdatedAt DATETIME NOT NULL, managerSealedKeyUpdatedAt DATETIME NOT NULL, employeeBlob LONGTEXT DEFAULT NULL, employeePublishedAt DATETIME DEFAULT NULL, managerBlob LONGTEXT DEFAULT NULL, managerPublishedAt DATETIME DEFAULT NULL, archivedAt DATETIME DEFAULT NULL, reminderSentAt DATETIME DEFAULT NULL, missed TINYINT NOT NULL, periodicityDays INT DEFAULT NULL, commentsBlob LONGTEXT DEFAULT NULL, commentsVersion INT NOT NULL, outcomesBlob LONGTEXT DEFAULT NULL, outcomesVersion INT NOT NULL, goalCheckpointsBlob LONGTEXT DEFAULT NULL, goalCheckpointsVersion INT NOT NULL, createdAt DATETIME NOT NULL, employee_id VARCHAR(36) NOT NULL, manager_id VARCHAR(36) NOT NULL, INDEX IDX_865B0D848C03F15C (employee_id), INDEX IDX_865B0D84783E3463 (manager_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE goals (id VARCHAR(36) NOT NULL, goalUuid VARCHAR(36) NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, targetDate DATE DEFAULT NULL, status VARCHAR(20) NOT NULL, createdAt DATETIME NOT NULL, anketa_id VARCHAR(36) NOT NULL, author_id VARCHAR(36) NOT NULL, INDEX IDX_C7241E2FAF307F7D (anketa_id), INDEX IDX_C7241E2FF675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE password_reset_tokens (id VARCHAR(36) NOT NULL, tokenHash VARCHAR(64) NOT NULL, email VARCHAR(255) NOT NULL, expiresAt DATETIME NOT NULL, usedAt DATETIME DEFAULT NULL, createdAt DATETIME NOT NULL, UNIQUE INDEX UNIQ_3967A216E5C96920 (tokenHash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE users (id VARCHAR(36) NOT NULL, email VARCHAR(255) NOT NULL, authHash VARCHAR(255) NOT NULL, publicKey LONGTEXT NOT NULL, encryptedPrivateKey LONGTEXT NOT NULL, publicKeyUpdatedAt DATETIME DEFAULT NULL, createdAt DATETIME NOT NULL, isAdmin TINYINT NOT NULL, isBlocked TINYINT NOT NULL, locale VARCHAR(5) NOT NULL, meetingRemindersEnabled TINYINT NOT NULL, deletedAt DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_1483A5E9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE anketas ADD CONSTRAINT FK_865B0D848C03F15C FOREIGN KEY (employee_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE anketas ADD CONSTRAINT FK_865B0D84783E3463 FOREIGN KEY (manager_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE goals ADD CONSTRAINT FK_C7241E2FAF307F7D FOREIGN KEY (anketa_id) REFERENCES anketas (id)');
        $this->addSql('ALTER TABLE goals ADD CONSTRAINT FK_C7241E2FF675F31B FOREIGN KEY (author_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE anketas DROP FOREIGN KEY FK_865B0D848C03F15C');
        $this->addSql('ALTER TABLE anketas DROP FOREIGN KEY FK_865B0D84783E3463');
        $this->addSql('ALTER TABLE goals DROP FOREIGN KEY FK_C7241E2FAF307F7D');
        $this->addSql('ALTER TABLE goals DROP FOREIGN KEY FK_C7241E2FF675F31B');
        $this->addSql('DROP TABLE activation_tokens');
        $this->addSql('DROP TABLE anketas');
        $this->addSql('DROP TABLE goals');
        $this->addSql('DROP TABLE password_reset_tokens');
        $this->addSql('DROP TABLE users');
    }
}
