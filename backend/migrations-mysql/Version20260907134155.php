<?php

declare(strict_types=1);

namespace App\Migrations\MySQL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MySQL counterpart of migrations/Version20260907134155.php — see that file's own
 * docblock. Hand-trimmed the same way: `doctrine:migrations:diff` also proposed two
 * unrelated `CHANGE ... NOT NULL` column redeclarations on anketas/users (pre-existing
 * DEFAULT-clause metadata drift on this side, not anything invite_records touches),
 * dropped here to keep this migration scoped to one concern, matching every other
 * migration in this history.
 */
final class Version20260907134155 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add invite_records (admin-facing invite history, separate from activation_tokens)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invite_records (id VARCHAR(36) NOT NULL, email VARCHAR(255) NOT NULL, createdAt DATETIME NOT NULL, expiresAt DATETIME NOT NULL, acceptedAt DATETIME DEFAULT NULL, company_id VARCHAR(36) NOT NULL, invitedBy_id VARCHAR(36) DEFAULT NULL, INDEX IDX_836AD4B2979B1AD6 (company_id), INDEX IDX_836AD4B28EEA691 (invitedBy_id), INDEX idx_invite_records_email (email), INDEX idx_invite_records_created_at (createdAt), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE invite_records ADD CONSTRAINT FK_836AD4B2979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id)');
        $this->addSql('ALTER TABLE invite_records ADD CONSTRAINT FK_836AD4B28EEA691 FOREIGN KEY (invitedBy_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invite_records DROP FOREIGN KEY FK_836AD4B2979B1AD6');
        $this->addSql('ALTER TABLE invite_records DROP FOREIGN KEY FK_836AD4B28EEA691');
        $this->addSql('DROP TABLE invite_records');
    }
}
