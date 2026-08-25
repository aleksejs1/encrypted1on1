<?php

declare(strict_types=1);

namespace App\Migrations\MySQL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written, MySQL-native counterpart to the SQLite Version20260825015824 migration —
 * see that file's own comment for what/why.
 */
final class Version20260825015900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Add users.displayName, defaulting to '' for every existing row";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD displayName VARCHAR(255) NOT NULL DEFAULT ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP displayName');
    }
}
