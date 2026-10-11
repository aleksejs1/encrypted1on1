<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * GitHub issue #266, part of #265 (company org structure): users.manager_id, who a person
 * reports to (see User::$manager). Nullable, so existing rows need no backfill.
 *
 * Generated via `app:make-dual-migration` and trimmed: the diff rebuilt users (and,
 * unrelated, companies) where SQLite's ADD COLUMN takes a nullable foreign key as it
 * is. The constraint and index keep the generated names. down() is the generated users
 * rebuild, like every other column-removal migration here: SQLite can't DROP a column
 * that a foreign key uses.
 */
final class Version20261011022146 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.manager_id for the company org structure';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN manager_id VARCHAR(36) DEFAULT NULL CONSTRAINT FK_1483A5E9783E3463 REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_1483A5E9783E3463 ON users (manager_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__users AS SELECT id, email, displayName, authHash, publicKey, encryptedPrivateKey, publicKeyUpdatedAt, createdAt, isAdmin, isBlocked, isDemo, isPlatformAdmin, locale, meetingRemindersEnabled, deletedAt, company_id FROM users');
        $this->addSql('DROP TABLE users');
        $this->addSql('CREATE TABLE users (id VARCHAR(36) NOT NULL, email VARCHAR(255) NOT NULL, displayName VARCHAR(255) DEFAULT \'\' NOT NULL, authHash VARCHAR(255) NOT NULL, publicKey CLOB NOT NULL, encryptedPrivateKey CLOB NOT NULL, publicKeyUpdatedAt DATETIME DEFAULT NULL, createdAt DATETIME NOT NULL, isAdmin BOOLEAN NOT NULL, isBlocked BOOLEAN NOT NULL, isDemo BOOLEAN NOT NULL, isPlatformAdmin BOOLEAN DEFAULT 0 NOT NULL, locale VARCHAR(5) NOT NULL, meetingRemindersEnabled BOOLEAN NOT NULL, deletedAt DATETIME DEFAULT NULL, company_id VARCHAR(36) NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_1483A5E9979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO users (id, email, displayName, authHash, publicKey, encryptedPrivateKey, publicKeyUpdatedAt, createdAt, isAdmin, isBlocked, isDemo, isPlatformAdmin, locale, meetingRemindersEnabled, deletedAt, company_id) SELECT id, email, displayName, authHash, publicKey, encryptedPrivateKey, publicKeyUpdatedAt, createdAt, isAdmin, isBlocked, isDemo, isPlatformAdmin, locale, meetingRemindersEnabled, deletedAt, company_id FROM __temp__users');
        $this->addSql('DROP TABLE __temp__users');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('CREATE INDEX IDX_1483A5E9979B1AD6 ON users (company_id)');
    }
}
