<?php

declare(strict_types=1);

namespace App\Migrations\MySQL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written, MySQL-native counterpart to the SQLite
 * Version20260814152941 migration — see that file's own comment, and
 * migrations-mysql.php, for why this lives in a separate, standalone
 * namespace rather than being auto-generated/diffed against the bootstrap.
 */
final class Version20260814153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.isDemo, for the fixed demo account pair (see private/demo-mode-plan.md)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD isDemo TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP isDemo');
    }
}
