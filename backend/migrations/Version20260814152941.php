<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260814152941 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.isDemo, for the fixed demo account pair (see private/demo-mode-plan.md)';
    }

    public function up(Schema $schema): void
    {
        // A literal DEFAULT is required here — same lesson the
        // meetingRemindersEnabled migration already hit: SQLite's ADD COLUMN
        // rejects NOT NULL with no default against a populated table.
        $this->addSql('ALTER TABLE users ADD COLUMN isDemo BOOLEAN NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__users AS SELECT id, email, authHash, publicKey, encryptedPrivateKey, publicKeyUpdatedAt, createdAt, isAdmin, isBlocked, locale, meetingRemindersEnabled, deletedAt FROM users');
        $this->addSql('DROP TABLE users');
        $this->addSql('CREATE TABLE users (id VARCHAR(36) NOT NULL, email VARCHAR(255) NOT NULL, authHash VARCHAR(255) NOT NULL, publicKey CLOB NOT NULL, encryptedPrivateKey CLOB NOT NULL, publicKeyUpdatedAt DATETIME DEFAULT NULL, createdAt DATETIME NOT NULL, isAdmin BOOLEAN NOT NULL, isBlocked BOOLEAN NOT NULL, locale VARCHAR(5) NOT NULL, meetingRemindersEnabled BOOLEAN NOT NULL, deletedAt DATETIME DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO users (id, email, authHash, publicKey, encryptedPrivateKey, publicKeyUpdatedAt, createdAt, isAdmin, isBlocked, locale, meetingRemindersEnabled, deletedAt) SELECT id, email, authHash, publicKey, encryptedPrivateKey, publicKeyUpdatedAt, createdAt, isAdmin, isBlocked, locale, meetingRemindersEnabled, deletedAt FROM __temp__users');
        $this->addSql('DROP TABLE __temp__users');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
    }
}
