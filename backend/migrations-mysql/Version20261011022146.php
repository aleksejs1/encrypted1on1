<?php

declare(strict_types=1);

namespace App\Migrations\MySQL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MySQL-native counterpart to the SQLite Version20261011022146 migration — adds
 * users.manager_id (GitHub issue #266, part of #265). Generated via `app:make-dual-migration` and
 * trimmed of the unrelated column-default changes the diff re-emits. No COLLATE: the
 * new column takes the table's own utf8mb4_unicode_ci, the same as users.id, which the
 * foreign key needs (checked against a real MySQL 8.4).
 */
final class Version20261011022146 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.manager_id for the company org structure';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD manager_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9783E3463 FOREIGN KEY (manager_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_1483A5E9783E3463 ON users (manager_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY FK_1483A5E9783E3463');
        $this->addSql('DROP INDEX IDX_1483A5E9783E3463 ON users');
        $this->addSql('ALTER TABLE users DROP manager_id');
    }
}
