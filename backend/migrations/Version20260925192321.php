<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds anketa_private_notes (GitHub issues #132, #136, see AnketaPrivateNote) — one
 * participant's private, per-anketa notes: a wrapped notes key and a ciphertext blob,
 * readable only by the author. A new table only, so no existing row is touched.
 *
 * Generated via `app:make-dual-migration`, trimmed to the anketa_private_notes
 * statements only (the diff also re-emitted unrelated companies/users table recreations
 * with no real schema change).
 */
final class Version20260925192321 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anketa_private_notes for per-user encrypted private notes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE anketa_private_notes (id VARCHAR(36) NOT NULL, encryptedNotesKey CLOB NOT NULL, notesBlob CLOB NOT NULL, version INTEGER NOT NULL, updatedAt DATETIME NOT NULL, anketa_id VARCHAR(36) NOT NULL, author_id VARCHAR(36) NOT NULL, company_id VARCHAR(36) NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_843B7ECFAF307F7D FOREIGN KEY (anketa_id) REFERENCES anketas (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_843B7ECFF675F31B FOREIGN KEY (author_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_843B7ECF979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_843B7ECFAF307F7D ON anketa_private_notes (anketa_id)');
        $this->addSql('CREATE INDEX IDX_843B7ECFF675F31B ON anketa_private_notes (author_id)');
        $this->addSql('CREATE INDEX IDX_843B7ECF979B1AD6 ON anketa_private_notes (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_anketa_private_notes_anketa_author ON anketa_private_notes (anketa_id, author_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE anketa_private_notes');
    }
}
