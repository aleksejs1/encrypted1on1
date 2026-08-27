<?php

declare(strict_types=1);

namespace App\Migrations\MySQL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written, MySQL-native counterpart to the SQLite Version20260825140000 migration —
 * see that file's own comment for what/why. Missed when that one was added; production
 * (MySQL cloud topology) hit "Unknown column 'a0_.formVersion'" until this landed.
 */
final class Version20260827120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anketas.formVersion, defaulting to 1 (the pre-versioning question set) for every existing row';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketas ADD formVersion INT NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketas DROP formVersion');
    }
}
