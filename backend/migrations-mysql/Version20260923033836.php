<?php

declare(strict_types=1);

namespace App\Migrations\MySQL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MySQL counterpart of migrations/Version20260923033728.php — adds anketas.oneOff
 * (GitHub issue #111, see Anketa::$oneOff). DEFAULT 0 backfills every pre-existing row,
 * the same way templateKey's literal default avoids the populated-table NOT NULL footgun
 * docs/deployment.md documents.
 *
 * Generated via `doctrine:migrations:diff --configuration=migrations-mysql.php` against a
 * real MySQL 8.4 database, trimmed to the oneOff column only (the diff also proposed
 * dropping unrelated columns' existing DB-level defaults, which isn't this change's job).
 */
final class Version20260923033836 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anketas.oneOff, defaulting existing and new rows to false';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketas ADD oneOff TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketas DROP oneOff');
    }
}
