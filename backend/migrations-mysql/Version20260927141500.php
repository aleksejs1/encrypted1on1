<?php

declare(strict_types=1);

namespace App\Migrations\MySQL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written, MySQL-native counterpart to the SQLite Version20260927141500 migration —
 * adds anketas.discussedBlob/discussedVersion (GitHub issue #168).
 */
final class Version20260927141500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anketas.discussedBlob/discussedVersion for live-synced question checkboxes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketas ADD discussedBlob LONGTEXT DEFAULT NULL, ADD discussedVersion INT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketas DROP discussedBlob, DROP discussedVersion');
    }
}
