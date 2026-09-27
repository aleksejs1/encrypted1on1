<?php

declare(strict_types=1);

namespace App\Migrations\MySQL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds custom_templates and custom_template_versions (GitHub issues #133, #142, see
 * CustomTemplate and CustomTemplateVersion): a company's library of admin-authored
 * anketa templates, each with append-only versions. New tables only, so no existing row
 * is touched.
 *
 * Generated via `app:make-dual-migration`, trimmed to the custom template statements
 * only (the diff also re-emitted unrelated anketas/users column changes with no real schema change).
 *
 * The collation is pinned by hand, for the same reason as
 * Version20260925192321's: without it MySQL 8.4 creates the tables as
 * utf8mb4_0900_ai_ci, and the foreign keys to the utf8mb4_unicode_ci companies/users
 * tables fail as incompatible.
 */
final class Version20260926182136 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add custom_templates and custom_template_versions for company-authored anketa templates';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE custom_templates (id VARCHAR(36) NOT NULL, currentVersion INT NOT NULL, archivedAt DATETIME DEFAULT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, company_id VARCHAR(36) NOT NULL, createdBy_id VARCHAR(36) NOT NULL, INDEX IDX_25120A28979B1AD6 (company_id), INDEX IDX_25120A283174800F (createdBy_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE custom_template_versions (id VARCHAR(36) NOT NULL, version INT NOT NULL, name VARCHAR(120) NOT NULL, description VARCHAR(300) DEFAULT \'\' NOT NULL, definition LONGTEXT NOT NULL, createdAt DATETIME NOT NULL, template_id VARCHAR(36) NOT NULL, company_id VARCHAR(36) NOT NULL, createdBy_id VARCHAR(36) NOT NULL, INDEX IDX_B4C5142C5DA0FB8 (template_id), INDEX IDX_B4C5142C979B1AD6 (company_id), INDEX IDX_B4C5142C3174800F (createdBy_id), UNIQUE INDEX uniq_custom_template_versions_template_version (template_id, version), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE custom_template_versions ADD CONSTRAINT FK_B4C5142C5DA0FB8 FOREIGN KEY (template_id) REFERENCES custom_templates (id)');
        $this->addSql('ALTER TABLE custom_template_versions ADD CONSTRAINT FK_B4C5142C979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id)');
        $this->addSql('ALTER TABLE custom_template_versions ADD CONSTRAINT FK_B4C5142C3174800F FOREIGN KEY (createdBy_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE custom_templates ADD CONSTRAINT FK_25120A28979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id)');
        $this->addSql('ALTER TABLE custom_templates ADD CONSTRAINT FK_25120A283174800F FOREIGN KEY (createdBy_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE custom_template_versions DROP FOREIGN KEY FK_B4C5142C5DA0FB8');
        $this->addSql('ALTER TABLE custom_template_versions DROP FOREIGN KEY FK_B4C5142C979B1AD6');
        $this->addSql('ALTER TABLE custom_template_versions DROP FOREIGN KEY FK_B4C5142C3174800F');
        $this->addSql('ALTER TABLE custom_templates DROP FOREIGN KEY FK_25120A28979B1AD6');
        $this->addSql('ALTER TABLE custom_templates DROP FOREIGN KEY FK_25120A283174800F');
        $this->addSql('DROP TABLE custom_template_versions');
        $this->addSql('DROP TABLE custom_templates');
    }
}
