<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * GitHub issue #169 (expired invite recovery):
 * - invite_records.renewalRequestedAt (see InviteRecord::$renewalRequestedAt): when the
 *   invitee last asked, from an expired link, for a new invite. Nullable, so existing
 *   rows need no backfill.
 * - Indexes on activation_tokens.email and expiresAt: rows are now kept two weeks past
 *   expiry, looked up by address (InviteRenewal, AccountDeleter) and pruned by expiresAt.
 *
 * Generated via `app:make-dual-migration` and trimmed: the diff also re-emitted unrelated
 * companies/users rebuilds, and rebuilt activation_tokens where a plain CREATE INDEX
 * does. down()'s invite_records temp-table recreate is the generated one, like every
 * other column-removal migration here.
 */
final class Version20261001122844 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add invite_records.renewalRequestedAt and activation_tokens email/expiresAt indexes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invite_records ADD COLUMN renewalRequestedAt DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_activation_tokens_email ON activation_tokens (email)');
        $this->addSql('CREATE INDEX idx_activation_tokens_expires_at ON activation_tokens (expiresAt)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_activation_tokens_email');
        $this->addSql('DROP INDEX idx_activation_tokens_expires_at');
        $this->addSql('CREATE TEMPORARY TABLE __temp__invite_records AS SELECT id, email, createdAt, expiresAt, acceptedAt, company_id, invitedBy_id FROM invite_records');
        $this->addSql('DROP TABLE invite_records');
        $this->addSql('CREATE TABLE invite_records (id VARCHAR(36) NOT NULL, email VARCHAR(255) NOT NULL, createdAt DATETIME NOT NULL, expiresAt DATETIME NOT NULL, acceptedAt DATETIME DEFAULT NULL, company_id VARCHAR(36) NOT NULL, invitedBy_id VARCHAR(36) DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_836AD4B2979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_836AD4B28EEA691 FOREIGN KEY (invitedBy_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO invite_records (id, email, createdAt, expiresAt, acceptedAt, company_id, invitedBy_id) SELECT id, email, createdAt, expiresAt, acceptedAt, company_id, invitedBy_id FROM __temp__invite_records');
        $this->addSql('DROP TABLE __temp__invite_records');
        $this->addSql('CREATE INDEX idx_invite_records_email ON invite_records (email)');
        $this->addSql('CREATE INDEX idx_invite_records_created_at ON invite_records (createdAt)');
        $this->addSql('CREATE INDEX IDX_836AD4B2979B1AD6 ON invite_records (company_id)');
        $this->addSql('CREATE INDEX IDX_836AD4B28EEA691 ON invite_records (invitedBy_id)');
    }
}
