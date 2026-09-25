<?php

declare(strict_types=1);

namespace App\Migrations\MySQL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds anketa_private_notes (GitHub issues #132, #136, see AnketaPrivateNote) — one
 * participant's private, per-anketa notes: a wrapped notes key and a ciphertext blob,
 * readable only by the author. A new table only, so no existing row is touched.
 *
 * Generated via `app:make-dual-migration`, trimmed to the anketa_private_notes
 * statements only (the diff also re-emitted unrelated anketas/users column changes
 * with no real schema change).
 *
 * The collation is pinned by hand: every earlier table was created as
 * utf8mb4_unicode_ci, but this diff no longer emits a COLLATE, and MySQL 8.4's default
 * (utf8mb4_0900_ai_ci) makes the foreign keys to anketas/users/companies fail as
 * incompatible (error 3780, reproduced against a real MySQL 8.4).
 */
final class Version20260925192321 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anketa_private_notes for per-user encrypted private notes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE anketa_private_notes (id VARCHAR(36) NOT NULL, encryptedNotesKey LONGTEXT NOT NULL, notesBlob LONGTEXT NOT NULL, version INT NOT NULL, updatedAt DATETIME NOT NULL, anketa_id VARCHAR(36) NOT NULL, author_id VARCHAR(36) NOT NULL, company_id VARCHAR(36) NOT NULL, INDEX IDX_843B7ECFAF307F7D (anketa_id), INDEX IDX_843B7ECFF675F31B (author_id), INDEX IDX_843B7ECF979B1AD6 (company_id), UNIQUE INDEX uniq_anketa_private_notes_anketa_author (anketa_id, author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE anketa_private_notes ADD CONSTRAINT FK_843B7ECFAF307F7D FOREIGN KEY (anketa_id) REFERENCES anketas (id)');
        $this->addSql('ALTER TABLE anketa_private_notes ADD CONSTRAINT FK_843B7ECFF675F31B FOREIGN KEY (author_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE anketa_private_notes ADD CONSTRAINT FK_843B7ECF979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketa_private_notes DROP FOREIGN KEY FK_843B7ECFAF307F7D');
        $this->addSql('ALTER TABLE anketa_private_notes DROP FOREIGN KEY FK_843B7ECFF675F31B');
        $this->addSql('ALTER TABLE anketa_private_notes DROP FOREIGN KEY FK_843B7ECF979B1AD6');
        $this->addSql('DROP TABLE anketa_private_notes');
    }
}
