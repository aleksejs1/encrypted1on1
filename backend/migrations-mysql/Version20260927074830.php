<?php

declare(strict_types=1);

namespace App\Migrations\MySQL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds anketas.customTemplateVersion_id (GitHub issues #133, #144, see
 * Anketa::$customTemplateVersion): the company template version a 'custom' anketa
 * renders. Nullable and NULL for every existing row, which all use a built-in template,
 * so it's safe on a populated table.
 *
 * Generated via `app:make-dual-migration`, trimmed to the new column (the diff also
 * re-emitted unrelated anketas/users column changes with no real schema change).
 */
final class Version20260927074830 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anketas.customTemplateVersion_id for anketas on a company template';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketas ADD customTemplateVersion_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE anketas ADD CONSTRAINT FK_865B0D84E3829923 FOREIGN KEY (customTemplateVersion_id) REFERENCES custom_template_versions (id)');
        $this->addSql('CREATE INDEX IDX_865B0D84E3829923 ON anketas (customTemplateVersion_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketas DROP FOREIGN KEY FK_865B0D84E3829923');
        $this->addSql('DROP INDEX IDX_865B0D84E3829923 ON anketas');
        $this->addSql('ALTER TABLE anketas DROP customTemplateVersion_id');
    }
}
