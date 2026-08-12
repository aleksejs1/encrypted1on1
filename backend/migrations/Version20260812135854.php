<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260812135854 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE password_reset_tokens (id VARCHAR(36) NOT NULL, tokenHash VARCHAR(64) NOT NULL, email VARCHAR(255) NOT NULL, expiresAt DATETIME NOT NULL, usedAt DATETIME DEFAULT NULL, createdAt DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3967A216E5C96920 ON password_reset_tokens (tokenHash)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__users AS SELECT id, email, authHash, publicKey, encryptedPrivateKey, createdAt, isAdmin, isBlocked, locale FROM users');
        $this->addSql('DROP TABLE users');
        $this->addSql('CREATE TABLE users (id VARCHAR(36) NOT NULL, email VARCHAR(255) NOT NULL, authHash VARCHAR(255) NOT NULL, publicKey CLOB NOT NULL, encryptedPrivateKey CLOB NOT NULL, createdAt DATETIME NOT NULL, isAdmin BOOLEAN NOT NULL, isBlocked BOOLEAN NOT NULL, locale VARCHAR(5) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO users (id, email, authHash, publicKey, encryptedPrivateKey, createdAt, isAdmin, isBlocked, locale) SELECT id, email, authHash, publicKey, encryptedPrivateKey, createdAt, isAdmin, isBlocked, locale FROM __temp__users');
        $this->addSql('DROP TABLE __temp__users');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE password_reset_tokens');
        $this->addSql('CREATE TEMPORARY TABLE __temp__users AS SELECT id, email, authHash, publicKey, encryptedPrivateKey, createdAt, isAdmin, isBlocked, locale FROM users');
        $this->addSql('DROP TABLE users');
        $this->addSql('CREATE TABLE users (id VARCHAR(36) NOT NULL, email VARCHAR(255) NOT NULL, authHash VARCHAR(255) NOT NULL, publicKey CLOB NOT NULL, encryptedPrivateKey CLOB NOT NULL, createdAt DATETIME NOT NULL, isAdmin BOOLEAN NOT NULL, isBlocked BOOLEAN NOT NULL, locale VARCHAR(5) DEFAULT \'en\' NOT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO users (id, email, authHash, publicKey, encryptedPrivateKey, createdAt, isAdmin, isBlocked, locale) SELECT id, email, authHash, publicKey, encryptedPrivateKey, createdAt, isAdmin, isBlocked, locale FROM __temp__users');
        $this->addSql('DROP TABLE __temp__users');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
    }
}
