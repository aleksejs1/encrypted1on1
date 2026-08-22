<?php

namespace App\Command;

use App\Entity\ActivationToken;
use App\Entity\PasswordResetToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Meant to run periodically via an external cron entry (same ADR 5 no-worker-process
 * pattern as app:send-reminders/backup.sh — see docs/deployment.md) — deletes
 * ActivationToken/PasswordResetToken rows whose expiresAt has passed, used or not.
 * Nothing else in this app ever removes a row from either table, so without this both
 * grow forever; a used or expired token has no further function once it's past its TTL
 * (the raw token needed to redeem one was never stored to begin with — see each entity's
 * own tokenHash docblock), so there's nothing meaningful lost by deleting the row itself.
 * A bulk DQL DELETE, not an entity-by-entity load+remove+flush loop: neither entity
 * carries relations that need Doctrine's own cascade/event handling, so there's no
 * reason to pay the cost of hydrating every expired row first.
 */
#[AsCommand(name: 'app:cleanup-expired-tokens', description: 'Delete expired activation and password-reset tokens')]
class CleanupExpiredTokensCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        $deletedActivationTokens = $this->entityManager->createQueryBuilder()
            ->delete(ActivationToken::class, 't')
            ->where('t.expiresAt < :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
        // A bulk DQL DELETE's execute() always returns the affected-row count as an int
        // — untyped (mixed) only because the same Query::execute() also serves SELECTs.
        \assert(\is_int($deletedActivationTokens));

        $deletedPasswordResetTokens = $this->entityManager->createQueryBuilder()
            ->delete(PasswordResetToken::class, 't')
            ->where('t.expiresAt < :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
        \assert(\is_int($deletedPasswordResetTokens));

        $io->success(sprintf(
            'Deleted %d expired activation token(s) and %d expired password-reset token(s).',
            $deletedActivationTokens,
            $deletedPasswordResetTokens,
        ));

        return Command::SUCCESS;
    }
}
