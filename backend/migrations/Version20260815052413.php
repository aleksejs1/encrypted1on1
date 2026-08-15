<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Introduces the `companies` table — the tenant boundary, Phase A of
 * private/cloud-service-plan.md (not tracked in git). Hand-edited from the
 * auto-generated version: the generated up() built `users`/`activation_tokens` with a
 * NOT NULL `company_id` column but its INSERT...SELECT never populated it, which would
 * fail outright against any database that already has rows (the exact class of bug
 * documented repeatedly elsewhere in this project's migration history — see e.g.
 * Version20260812142033). Fixed by seeding exactly one `Company` row first (fixed id,
 * so every backfilled row can reference it) and explicitly backfilling company_id to
 * that id in both rebuilt tables' INSERT statements.
 *
 * registrationMode='invite'/allowedEmailDomain='' match this project's own committed
 * `.env` defaults exactly — an operator who customized REGISTRATION_MODE/
 * ALLOWED_EMAIL_DOMAIN away from those defaults before upgrading needs to update this
 * seeded row's columns after migrating (e.g. via `bin/console dbal:run-sql`), since a
 * schema migration has no access to the old env values at the point it runs.
 */
final class Version20260815052413 extends AbstractMigration
{
    /**
     * Fixed rather than generated at migration-run time: every row backfilled below
     * needs to reference the exact same company, so the id has to be known up front.
     * Value is a real Uuid::v7() output, matching every other id in this app's schema —
     * not required for correctness (SQLite doesn't validate UUID shape), but consistent
     * with how every other id in this database looks.
     */
    private const string DEFAULT_COMPANY_ID = '01a003e1-030e-701a-b23e-e227eaf5c843';

    public function getDescription(): string
    {
        return 'Add companies table (tenant boundary) and users/activation_tokens.company_id, backfilling one seeded company for existing rows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE companies (id VARCHAR(36) NOT NULL, name VARCHAR(255) NOT NULL, registrationMode VARCHAR(20) NOT NULL, allowedEmailDomain VARCHAR(255) NOT NULL, createdAt DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql(sprintf(
            "INSERT INTO companies (id, name, registrationMode, allowedEmailDomain, createdAt) VALUES ('%s', 'Default company', 'invite', '', datetime('now'))",
            self::DEFAULT_COMPANY_ID,
        ));

        $this->addSql('CREATE TEMPORARY TABLE __temp__activation_tokens AS SELECT id, tokenHash, email, grantsAdmin, expiresAt, usedAt, createdAt FROM activation_tokens');
        $this->addSql('DROP TABLE activation_tokens');
        $this->addSql('CREATE TABLE activation_tokens (id VARCHAR(36) NOT NULL, tokenHash VARCHAR(64) NOT NULL, email VARCHAR(255) NOT NULL, grantsAdmin BOOLEAN NOT NULL, expiresAt DATETIME NOT NULL, usedAt DATETIME DEFAULT NULL, createdAt DATETIME NOT NULL, company_id VARCHAR(36) NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_C1DFC359979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql(sprintf(
            "INSERT INTO activation_tokens (id, tokenHash, email, grantsAdmin, expiresAt, usedAt, createdAt, company_id) SELECT id, tokenHash, email, grantsAdmin, expiresAt, usedAt, createdAt, '%s' FROM __temp__activation_tokens",
            self::DEFAULT_COMPANY_ID,
        ));
        $this->addSql('DROP TABLE __temp__activation_tokens');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C1DFC359E5C96920 ON activation_tokens (tokenHash)');
        $this->addSql('CREATE INDEX IDX_C1DFC359979B1AD6 ON activation_tokens (company_id)');

        $this->addSql('CREATE TEMPORARY TABLE __temp__users AS SELECT id, email, authHash, publicKey, encryptedPrivateKey, createdAt, isAdmin, isBlocked, locale, publicKeyUpdatedAt, meetingRemindersEnabled, deletedAt, isDemo FROM users');
        $this->addSql('DROP TABLE users');
        $this->addSql('CREATE TABLE users (id VARCHAR(36) NOT NULL, email VARCHAR(255) NOT NULL, authHash VARCHAR(255) NOT NULL, publicKey CLOB NOT NULL, encryptedPrivateKey CLOB NOT NULL, createdAt DATETIME NOT NULL, isAdmin BOOLEAN NOT NULL, isBlocked BOOLEAN NOT NULL, locale VARCHAR(5) NOT NULL, publicKeyUpdatedAt DATETIME DEFAULT NULL, meetingRemindersEnabled BOOLEAN NOT NULL, deletedAt DATETIME DEFAULT NULL, isDemo BOOLEAN NOT NULL, company_id VARCHAR(36) NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_1483A5E9979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql(sprintf(
            "INSERT INTO users (id, email, authHash, publicKey, encryptedPrivateKey, createdAt, isAdmin, isBlocked, locale, publicKeyUpdatedAt, meetingRemindersEnabled, deletedAt, isDemo, company_id) SELECT id, email, authHash, publicKey, encryptedPrivateKey, createdAt, isAdmin, isBlocked, locale, publicKeyUpdatedAt, meetingRemindersEnabled, deletedAt, isDemo, '%s' FROM __temp__users",
            self::DEFAULT_COMPANY_ID,
        ));
        $this->addSql('DROP TABLE __temp__users');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('CREATE INDEX IDX_1483A5E9979B1AD6 ON users (company_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE companies');
        $this->addSql('CREATE TEMPORARY TABLE __temp__activation_tokens AS SELECT id, tokenHash, email, grantsAdmin, expiresAt, usedAt, createdAt FROM activation_tokens');
        $this->addSql('DROP TABLE activation_tokens');
        $this->addSql('CREATE TABLE activation_tokens (id VARCHAR(36) NOT NULL, tokenHash VARCHAR(64) NOT NULL, email VARCHAR(255) NOT NULL, grantsAdmin BOOLEAN NOT NULL, expiresAt DATETIME NOT NULL, usedAt DATETIME DEFAULT NULL, createdAt DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO activation_tokens (id, tokenHash, email, grantsAdmin, expiresAt, usedAt, createdAt) SELECT id, tokenHash, email, grantsAdmin, expiresAt, usedAt, createdAt FROM __temp__activation_tokens');
        $this->addSql('DROP TABLE __temp__activation_tokens');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C1DFC359E5C96920 ON activation_tokens (tokenHash)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__users AS SELECT id, email, authHash, publicKey, encryptedPrivateKey, publicKeyUpdatedAt, createdAt, isAdmin, isBlocked, isDemo, locale, meetingRemindersEnabled, deletedAt FROM users');
        $this->addSql('DROP TABLE users');
        $this->addSql('CREATE TABLE users (id VARCHAR(36) NOT NULL, email VARCHAR(255) NOT NULL, authHash VARCHAR(255) NOT NULL, publicKey CLOB NOT NULL, encryptedPrivateKey CLOB NOT NULL, publicKeyUpdatedAt DATETIME DEFAULT NULL, createdAt DATETIME NOT NULL, isAdmin BOOLEAN NOT NULL, isBlocked BOOLEAN NOT NULL, isDemo BOOLEAN DEFAULT 0 NOT NULL, locale VARCHAR(5) NOT NULL, meetingRemindersEnabled BOOLEAN NOT NULL, deletedAt DATETIME DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO users (id, email, authHash, publicKey, encryptedPrivateKey, publicKeyUpdatedAt, createdAt, isAdmin, isBlocked, isDemo, locale, meetingRemindersEnabled, deletedAt) SELECT id, email, authHash, publicKey, encryptedPrivateKey, publicKeyUpdatedAt, createdAt, isAdmin, isBlocked, isDemo, locale, meetingRemindersEnabled, deletedAt FROM __temp__users');
        $this->addSql('DROP TABLE __temp__users');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
    }
}
