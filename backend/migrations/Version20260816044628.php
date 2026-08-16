<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds users.isPlatformAdmin (Phase C of private/cloud-service-plan.md, not tracked in
 * git) — the SaaS operator's own cross-company support/moderation flag, separate from
 * the now-company-scoped isAdmin.
 */
final class Version20260816044628 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.isPlatformAdmin, defaulting to false for every existing row';
    }

    public function up(Schema $schema): void
    {
        // A literal DEFAULT is required here — same lesson isDemo's and
        // meetingRemindersEnabled's own migrations already hit: SQLite's ADD COLUMN
        // rejects NOT NULL with no default against a populated table.
        $this->addSql('ALTER TABLE users ADD COLUMN isPlatformAdmin BOOLEAN NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__users AS SELECT id, email, authHash, publicKey, encryptedPrivateKey, publicKeyUpdatedAt, createdAt, isAdmin, isBlocked, isDemo, locale, meetingRemindersEnabled, deletedAt, company_id FROM users');
        $this->addSql('DROP TABLE users');
        $this->addSql('CREATE TABLE users (id VARCHAR(36) NOT NULL, email VARCHAR(255) NOT NULL, authHash VARCHAR(255) NOT NULL, publicKey CLOB NOT NULL, encryptedPrivateKey CLOB NOT NULL, publicKeyUpdatedAt DATETIME DEFAULT NULL, createdAt DATETIME NOT NULL, isAdmin BOOLEAN NOT NULL, isBlocked BOOLEAN NOT NULL, isDemo BOOLEAN NOT NULL, locale VARCHAR(5) NOT NULL, meetingRemindersEnabled BOOLEAN NOT NULL, deletedAt DATETIME DEFAULT NULL, company_id VARCHAR(36) NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_1483A5E9979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO users (id, email, authHash, publicKey, encryptedPrivateKey, publicKeyUpdatedAt, createdAt, isAdmin, isBlocked, isDemo, locale, meetingRemindersEnabled, deletedAt, company_id) SELECT id, email, authHash, publicKey, encryptedPrivateKey, publicKeyUpdatedAt, createdAt, isAdmin, isBlocked, isDemo, locale, meetingRemindersEnabled, deletedAt, company_id FROM __temp__users');
        $this->addSql('DROP TABLE __temp__users');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('CREATE INDEX IDX_1483A5E9979B1AD6 ON users (company_id)');
    }
}
