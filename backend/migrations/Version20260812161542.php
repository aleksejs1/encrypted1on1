<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260812161542 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Hand-edited from the auto-generated version: unlike Version20260812142033's
        // NOT-NULL-with-no-default bug (which needed a full temp-table rebuild), a NOT
        // NULL column with a literal DEFAULT is something SQLite's own ALTER TABLE ADD
        // COLUMN supports directly, even against a populated table — verified for real
        // against the dev DB before considering this safe, not assumed just because the
        // SQL looks different from that earlier fix.
        $this->addSql('ALTER TABLE users ADD COLUMN meetingRemindersEnabled BOOLEAN NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__users AS SELECT id, email, authHash, publicKey, encryptedPrivateKey, publicKeyUpdatedAt, createdAt, isAdmin, isBlocked, locale FROM users');
        $this->addSql('DROP TABLE users');
        $this->addSql('CREATE TABLE users (id VARCHAR(36) NOT NULL, email VARCHAR(255) NOT NULL, authHash VARCHAR(255) NOT NULL, publicKey CLOB NOT NULL, encryptedPrivateKey CLOB NOT NULL, publicKeyUpdatedAt DATETIME DEFAULT NULL, createdAt DATETIME NOT NULL, isAdmin BOOLEAN NOT NULL, isBlocked BOOLEAN NOT NULL, locale VARCHAR(5) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO users (id, email, authHash, publicKey, encryptedPrivateKey, publicKeyUpdatedAt, createdAt, isAdmin, isBlocked, locale) SELECT id, email, authHash, publicKey, encryptedPrivateKey, publicKeyUpdatedAt, createdAt, isAdmin, isBlocked, locale FROM __temp__users');
        $this->addSql('DROP TABLE __temp__users');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
    }
}
