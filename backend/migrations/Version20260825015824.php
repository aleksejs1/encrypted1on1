<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds users.displayName — a plaintext (not encrypted) first/last name a user can set at
 * registration or in account settings, shown throughout the UI instead of raw email/uuid.
 * Empty string (the default for every existing row) means "not set"; every display site
 * falls back to email in that case (see frontend/src/userDisplay.ts).
 */
final class Version20260825015824 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Add users.displayName, defaulting to '' for every existing row";
    }

    public function up(Schema $schema): void
    {
        // A literal DEFAULT is required here — same lesson isDemo's/isPlatformAdmin's own
        // migrations already hit: SQLite's ADD COLUMN rejects NOT NULL with no default
        // against a populated table.
        $this->addSql("ALTER TABLE users ADD COLUMN displayName VARCHAR(255) NOT NULL DEFAULT ''");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__users AS SELECT id, email, authHash, publicKey, encryptedPrivateKey, publicKeyUpdatedAt, createdAt, isAdmin, isBlocked, isDemo, isPlatformAdmin, locale, meetingRemindersEnabled, deletedAt, company_id FROM users');
        $this->addSql('DROP TABLE users');
        $this->addSql('CREATE TABLE users (id VARCHAR(36) NOT NULL, email VARCHAR(255) NOT NULL, authHash VARCHAR(255) NOT NULL, publicKey CLOB NOT NULL, encryptedPrivateKey CLOB NOT NULL, publicKeyUpdatedAt DATETIME DEFAULT NULL, createdAt DATETIME NOT NULL, isAdmin BOOLEAN NOT NULL, isBlocked BOOLEAN NOT NULL, isDemo BOOLEAN NOT NULL, isPlatformAdmin BOOLEAN NOT NULL, locale VARCHAR(5) NOT NULL, meetingRemindersEnabled BOOLEAN NOT NULL, deletedAt DATETIME DEFAULT NULL, company_id VARCHAR(36) NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_1483A5E9979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO users (id, email, authHash, publicKey, encryptedPrivateKey, publicKeyUpdatedAt, createdAt, isAdmin, isBlocked, isDemo, isPlatformAdmin, locale, meetingRemindersEnabled, deletedAt, company_id) SELECT id, email, authHash, publicKey, encryptedPrivateKey, publicKeyUpdatedAt, createdAt, isAdmin, isBlocked, isDemo, isPlatformAdmin, locale, meetingRemindersEnabled, deletedAt, company_id FROM __temp__users');
        $this->addSql('DROP TABLE __temp__users');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('CREATE INDEX IDX_1483A5E9979B1AD6 ON users (company_id)');
    }
}
