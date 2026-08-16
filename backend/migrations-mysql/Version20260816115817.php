<?php

declare(strict_types=1);

namespace App\Migrations\MySQL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Catches up migrations-mysql/ with three SQLite migrations' worth of schema changes
 * from the multi-tenant work (private/cloud-service-plan.md, not tracked in git, Phases
 * A/C/D): the companies table, users/activation_tokens.company_id, users.isPlatformAdmin,
 * and companies' billing fields. This one MySQL migration bundles all three, matching the
 * established precedent (Version20260814153000 already bundled several SQLite migrations'
 * worth of changes the same way) — migrations-mysql/ isn't a 1:1 mirror of migrations/,
 * just a schema that reaches the same end state.
 *
 * Hand-edited from the auto-generated version, which added `company_id`/`isPlatformAdmin`
 * as bare `NOT NULL` with no explicit DEFAULT. Confirmed for real, not assumed, that this
 * is a genuine bug on a populated table, a different failure mode than SQLite's own
 * (which fails loudly): MySQL's ADD COLUMN NOT NULL with no DEFAULT silently backfills
 * each existing row with the column type's own implicit zero-value — for `company_id`
 * (VARCHAR) that's an empty string, not a real company id, which then made the very next
 * statement (adding the foreign key constraint) fail with a real, reproduced
 * `SQLSTATE[23000]` integrity-constraint error — worse than SQLite's failure, since the
 * column briefly holds silently-wrong data before the FK step catches it. Fixed with a
 * real backfill dance: add the columns nullable first, seed one default Company row
 * (fixed id, matching the SQLite migration's own convention), backfill every existing
 * row to point at it, add the foreign keys, then tighten to NOT NULL — the same shape
 * Version20260815052413 (the SQLite equivalent) already uses, adapted to MySQL's real
 * `ALTER TABLE ... MODIFY` support instead of SQLite's temp-table-rebuild dance.
 */
final class Version20260816115817 extends AbstractMigration
{
    /** Same value as the SQLite migration's own DEFAULT_COMPANY_ID constant would be if this ran on the exact same install — in practice these two migration paths are mutually exclusive (one database is ever either SQLite or MySQL), so the two ids never actually need to match each other, just be internally consistent within this file. */
    private const string DEFAULT_COMPANY_ID = '01a00a71-7a12-7c81-b4f7-70b36bb35f49';

    public function getDescription(): string
    {
        return 'Add companies table (tenant boundary + billing fields) and users.isPlatformAdmin/company_id, activation_tokens.company_id, backfilling one seeded company for any existing rows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE companies (id VARCHAR(36) NOT NULL, name VARCHAR(255) NOT NULL, registrationMode VARCHAR(20) NOT NULL, allowedEmailDomain VARCHAR(255) NOT NULL, planTier VARCHAR(40) NOT NULL, seatLimit INT DEFAULT NULL, subscriptionStatus VARCHAR(20) NOT NULL, trialEndsAt DATETIME DEFAULT NULL, suspendedAt DATETIME DEFAULT NULL, stripeCustomerId VARCHAR(255) DEFAULT NULL, stripeSubscriptionId VARCHAR(255) DEFAULT NULL, createdAt DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql(sprintf(
            "INSERT INTO companies (id, name, registrationMode, allowedEmailDomain, planTier, seatLimit, subscriptionStatus, trialEndsAt, suspendedAt, stripeCustomerId, stripeSubscriptionId, createdAt) VALUES ('%s', 'Default company', 'invite', '', 'free', NULL, 'active', NULL, NULL, NULL, NULL, NOW())",
            self::DEFAULT_COMPANY_ID,
        ));

        $this->addSql('ALTER TABLE activation_tokens ADD company_id VARCHAR(36) DEFAULT NULL');
        $this->addSql(sprintf("UPDATE activation_tokens SET company_id = '%s' WHERE company_id IS NULL", self::DEFAULT_COMPANY_ID));
        $this->addSql('ALTER TABLE activation_tokens MODIFY company_id VARCHAR(36) NOT NULL');
        $this->addSql('ALTER TABLE activation_tokens ADD CONSTRAINT FK_C1DFC359979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id)');
        $this->addSql('CREATE INDEX IDX_C1DFC359979B1AD6 ON activation_tokens (company_id)');

        $this->addSql('ALTER TABLE users ADD isPlatformAdmin TINYINT NOT NULL DEFAULT 0, ADD company_id VARCHAR(36) DEFAULT NULL');
        $this->addSql(sprintf("UPDATE users SET company_id = '%s' WHERE company_id IS NULL", self::DEFAULT_COMPANY_ID));
        $this->addSql('ALTER TABLE users MODIFY company_id VARCHAR(36) NOT NULL');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id)');
        $this->addSql('CREATE INDEX IDX_1483A5E9979B1AD6 ON users (company_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activation_tokens DROP FOREIGN KEY FK_C1DFC359979B1AD6');
        $this->addSql('DROP INDEX IDX_C1DFC359979B1AD6 ON activation_tokens');
        $this->addSql('ALTER TABLE activation_tokens DROP company_id');
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY FK_1483A5E9979B1AD6');
        $this->addSql('DROP INDEX IDX_1483A5E9979B1AD6 ON users');
        $this->addSql('ALTER TABLE users DROP isPlatformAdmin, DROP company_id');
        $this->addSql('DROP TABLE companies');
    }
}
