<?php

declare(strict_types=1);

namespace App\Migrations\MySQL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written, MySQL-native counterpart to the SQLite Version20260907034801 migration —
 * see that file's own comment for what/why. Explicit DEFAULT 0 on both columns, not left
 * to `doctrine:migrations:diff`'s auto-generated no-default ADD COLUMN, per the real
 * silent-corruption lesson from the companies/users MySQL catch-up migration (docs/history.md,
 * Phase E): a `NOT NULL` column added with no literal default can silently backfill existing
 * rows with a type-implicit zero-value instead of failing loudly.
 */
final class Version20260907034936 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anketas.employeeBlobVersion/managerBlobVersion, both defaulting to 0';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketas ADD employeeBlobVersion INT NOT NULL DEFAULT 0, ADD managerBlobVersion INT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketas DROP employeeBlobVersion, DROP managerBlobVersion');
    }
}
