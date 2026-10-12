<?php

declare(strict_types=1);

namespace App\Migrations\MySQL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written, MySQL-native counterpart to the SQLite Version20261012020510 migration —
 * adds companies.subscriptionPeriodEndsAt/subscriptionCancelsAt (GitHub issue #291).
 */
final class Version20261012020510 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add companies.subscriptionPeriodEndsAt/subscriptionCancelsAt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies ADD subscriptionPeriodEndsAt DATETIME DEFAULT NULL, ADD subscriptionCancelsAt DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies DROP subscriptionPeriodEndsAt, DROP subscriptionCancelsAt');
    }
}
