<?php

declare(strict_types=1);

namespace App\Migrations\MySQL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written, MySQL-native counterpart to the SQLite Version20261004104608 migration —
 * adds anketas.topicsBlob/topicsVersion (GitHub issue #206).
 */
final class Version20261004104608 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anketas.topicsBlob/topicsVersion for the shared topics list';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketas ADD topicsBlob LONGTEXT DEFAULT NULL, ADD topicsVersion INT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketas DROP topicsBlob, DROP topicsVersion');
    }
}
