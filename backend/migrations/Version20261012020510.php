<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds companies.subscriptionPeriodEndsAt/subscriptionCancelsAt (GitHub issue #291, see
 * Company::$subscriptionPeriodEndsAt): the two subscription dates the Plan page shows.
 *
 * down() recreates the table instead of a bare DROP COLUMN, like every other SQLite
 * migration here.
 */
final class Version20261012020510 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add companies.subscriptionPeriodEndsAt/subscriptionCancelsAt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies ADD COLUMN subscriptionPeriodEndsAt DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE companies ADD COLUMN subscriptionCancelsAt DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__companies AS SELECT id, name, registrationMode, allowedEmailDomain, createdAt, planTier, seatLimit, subscriptionStatus, trialEndsAt, suspendedAt, stripeCustomerId, stripeSubscriptionId FROM companies');
        $this->addSql('DROP TABLE companies');
        $this->addSql('CREATE TABLE companies (id VARCHAR(36) NOT NULL, name VARCHAR(255) NOT NULL, registrationMode VARCHAR(20) NOT NULL, allowedEmailDomain VARCHAR(255) NOT NULL, createdAt DATETIME NOT NULL, planTier VARCHAR(40) NOT NULL DEFAULT \'free\', seatLimit INTEGER DEFAULT NULL, subscriptionStatus VARCHAR(20) NOT NULL DEFAULT \'active\', trialEndsAt DATETIME DEFAULT NULL, suspendedAt DATETIME DEFAULT NULL, stripeCustomerId VARCHAR(255) DEFAULT NULL, stripeSubscriptionId VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO companies (id, name, registrationMode, allowedEmailDomain, createdAt, planTier, seatLimit, subscriptionStatus, trialEndsAt, suspendedAt, stripeCustomerId, stripeSubscriptionId) SELECT id, name, registrationMode, allowedEmailDomain, createdAt, planTier, seatLimit, subscriptionStatus, trialEndsAt, suspendedAt, stripeCustomerId, stripeSubscriptionId FROM __temp__companies');
        $this->addSql('DROP TABLE __temp__companies');
    }
}
