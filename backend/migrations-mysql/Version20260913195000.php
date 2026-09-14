<?php

declare(strict_types=1);

namespace App\Migrations\MySQL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MySQL counterpart of migrations/Version20260913195000.php — denormalizes
 * company_id onto anketas (GitHub issue #69).
 *
 * Follows the established MySQL migration pattern (Version20260816115817):
 * add nullable column first, backfill from users via employee_id, tighten to
 * NOT NULL, add foreign key constraint and index.
 */
final class Version20260913195000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anketas.company_id foreign key, backfilled from employee company_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketas ADD company_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('UPDATE anketas a JOIN users u ON u.id = a.employee_id SET a.company_id = u.company_id WHERE a.company_id IS NULL');
        $this->addSql('ALTER TABLE anketas MODIFY company_id VARCHAR(36) NOT NULL');
        $this->addSql('ALTER TABLE anketas ADD CONSTRAINT FK_865B0D84979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id)');
        $this->addSql('CREATE INDEX IDX_865B0D84979B1AD6 ON anketas (company_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketas DROP FOREIGN KEY FK_865B0D84979B1AD6');
        $this->addSql('DROP INDEX IDX_865B0D84979B1AD6 ON anketas');
        $this->addSql('ALTER TABLE anketas DROP company_id');
    }
}
