<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds invite_records — admin-facing invite history, deliberately separate from
 * activation_tokens (see InviteRecord's own docblock and GitHub issue #24). A plain
 * CREATE TABLE, hand-trimmed from `doctrine:migrations:diff`'s raw output: that command
 * also proposed rebuilding anketas/companies/users (SQLite's usual full-table-rebuild
 * quirk for any ALTER, triggered here by pre-existing FK-clause representation drift
 * unrelated to this change — confirmed by running the same diff against this branch's
 * base commit, which reproduces the identical anketas/companies/users rebuild with no
 * invite_records involved at all).
 */
final class Version20260907134155 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add invite_records (admin-facing invite history, separate from activation_tokens)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invite_records (id VARCHAR(36) NOT NULL, email VARCHAR(255) NOT NULL, createdAt DATETIME NOT NULL, expiresAt DATETIME NOT NULL, acceptedAt DATETIME DEFAULT NULL, company_id VARCHAR(36) NOT NULL, invitedBy_id VARCHAR(36) DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_836AD4B2979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_836AD4B28EEA691 FOREIGN KEY (invitedBy_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_836AD4B2979B1AD6 ON invite_records (company_id)');
        $this->addSql('CREATE INDEX IDX_836AD4B28EEA691 ON invite_records (invitedBy_id)');
        $this->addSql('CREATE INDEX idx_invite_records_email ON invite_records (email)');
        $this->addSql('CREATE INDEX idx_invite_records_created_at ON invite_records (createdAt)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE invite_records');
    }
}
