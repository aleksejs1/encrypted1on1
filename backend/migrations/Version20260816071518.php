<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds companies.{planTier,seatLimit,subscriptionStatus,trialEndsAt,suspendedAt,
 * stripeCustomerId,stripeSubscriptionId} — Phase D of private/cloud-service-plan.md (not
 * tracked in git), the billing scaffolding. Hand-edited from the auto-generated version:
 * planTier/subscriptionStatus are NOT NULL with no literal default in the generated SQL,
 * the same class of bug this project's migration history keeps hitting — fixed with
 * literal defaults matching Company's own constructor defaults ('free'/'active'), so
 * every existing row (the single self-hosted company) gets exactly what a freshly
 * constructed Company would have. Also dropped an unrelated `users` table rebuild the
 * diff tool included (a cosmetic FK-clause phrasing difference in Doctrine's own DDL
 * generation, not a real schema change — confirmed by checking the live schema already
 * matches the FK behavior either phrasing describes) to keep this migration scoped to
 * what actually changed.
 */
final class Version20260816071518 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add companies billing fields (planTier, seatLimit, subscriptionStatus, trialEndsAt, suspendedAt, stripeCustomerId, stripeSubscriptionId)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE companies ADD COLUMN planTier VARCHAR(40) NOT NULL DEFAULT 'free'");
        $this->addSql('ALTER TABLE companies ADD COLUMN seatLimit INTEGER DEFAULT NULL');
        $this->addSql("ALTER TABLE companies ADD COLUMN subscriptionStatus VARCHAR(20) NOT NULL DEFAULT 'active'");
        $this->addSql('ALTER TABLE companies ADD COLUMN trialEndsAt DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE companies ADD COLUMN suspendedAt DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE companies ADD COLUMN stripeCustomerId VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE companies ADD COLUMN stripeSubscriptionId VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__companies AS SELECT id, name, registrationMode, allowedEmailDomain, createdAt FROM companies');
        $this->addSql('DROP TABLE companies');
        $this->addSql('CREATE TABLE companies (id VARCHAR(36) NOT NULL, name VARCHAR(255) NOT NULL, registrationMode VARCHAR(20) NOT NULL, allowedEmailDomain VARCHAR(255) NOT NULL, createdAt DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO companies (id, name, registrationMode, allowedEmailDomain, createdAt) SELECT id, name, registrationMode, allowedEmailDomain, createdAt FROM __temp__companies');
        $this->addSql('DROP TABLE __temp__companies');
    }
}
