<?php

declare(strict_types=1);

namespace App\Migrations\MySQL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MySQL counterpart of migrations/Version20260920163822.php — adds
 * anketas.templateKey (private/anketa-meeting-templates-proposal.md, not tracked in
 * git). DEFAULT 'regular' backfills every pre-existing row for free — the literal
 * default is what avoids the populated-table NOT NULL footgun docs/deployment.md
 * documents (MySQL silently zero-value-backfills a bare NOT NULL instead of rejecting
 * it, which a real default sidesteps entirely).
 */
final class Version20260920163822 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Add anketas.templateKey, defaulting existing and new rows to 'regular'";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE anketas ADD templateKey VARCHAR(40) DEFAULT 'regular' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anketas DROP templateKey');
    }
}
