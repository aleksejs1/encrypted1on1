<?php

declare(strict_types=1);

namespace App\Migrations\MySQL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MySQL counterpart of migrations/Version20261001122844.php (GitHub issue #169):
 * invite_records.renewalRequestedAt and the activation_tokens email/expiresAt indexes.
 * Generated via `app:make-dual-migration` against MySQL 8.4 and trimmed (the diff also
 * re-emitted unrelated column-default drift on anketas/users). The new column is
 * nullable, so the populated-table NOT NULL backfill problem doesn't apply.
 */
final class Version20261001122844 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add invite_records.renewalRequestedAt and activation_tokens email/expiresAt indexes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invite_records ADD renewalRequestedAt DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_activation_tokens_email ON activation_tokens (email)');
        $this->addSql('CREATE INDEX idx_activation_tokens_expires_at ON activation_tokens (expiresAt)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_activation_tokens_email ON activation_tokens');
        $this->addSql('DROP INDEX idx_activation_tokens_expires_at ON activation_tokens');
        $this->addSql('ALTER TABLE invite_records DROP renewalRequestedAt');
    }
}
