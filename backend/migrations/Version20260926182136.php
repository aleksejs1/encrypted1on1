<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds custom_templates and custom_template_versions (GitHub issues #133, #142, see
 * CustomTemplate and CustomTemplateVersion): a company's library of admin-authored
 * anketa templates, each with append-only versions. New tables only, so no existing row
 * is touched.
 *
 * Generated via `app:make-dual-migration`, trimmed to the custom template statements
 * only (the diff also re-emitted unrelated companies/users table recreations with no real schema change).
 */
final class Version20260926182136 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add custom_templates and custom_template_versions for company-authored anketa templates';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE custom_templates (id VARCHAR(36) NOT NULL, currentVersion INTEGER NOT NULL, archivedAt DATETIME DEFAULT NULL, createdAt DATETIME NOT NULL, updatedAt DATETIME NOT NULL, company_id VARCHAR(36) NOT NULL, createdBy_id VARCHAR(36) NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_25120A28979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_25120A283174800F FOREIGN KEY (createdBy_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_25120A28979B1AD6 ON custom_templates (company_id)');
        $this->addSql('CREATE INDEX IDX_25120A283174800F ON custom_templates (createdBy_id)');
        $this->addSql('CREATE TABLE custom_template_versions (id VARCHAR(36) NOT NULL, version INTEGER NOT NULL, name VARCHAR(120) NOT NULL, description VARCHAR(300) DEFAULT \'\' NOT NULL, definition CLOB NOT NULL, createdAt DATETIME NOT NULL, template_id VARCHAR(36) NOT NULL, company_id VARCHAR(36) NOT NULL, createdBy_id VARCHAR(36) NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_B4C5142C5DA0FB8 FOREIGN KEY (template_id) REFERENCES custom_templates (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_B4C5142C979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_B4C5142C3174800F FOREIGN KEY (createdBy_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_B4C5142C5DA0FB8 ON custom_template_versions (template_id)');
        $this->addSql('CREATE INDEX IDX_B4C5142C979B1AD6 ON custom_template_versions (company_id)');
        $this->addSql('CREATE INDEX IDX_B4C5142C3174800F ON custom_template_versions (createdBy_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_custom_template_versions_template_version ON custom_template_versions (template_id, version)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE custom_template_versions');
        $this->addSql('DROP TABLE custom_templates');
    }
}
