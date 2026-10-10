<?php

namespace App\Command;

use App\Entity\ActivationToken;
use App\Entity\InviteRecord;
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
 * PasswordResetToken rows whose expiresAt has passed, and ActivationToken rows
 * ActivationToken::RETENTION_DAYS_AFTER_EXPIRY days after theirs, used or not. Nothing
 * else in this app ever removes a row from either table, so without this both grow
 * forever. A password-reset token has no further function once it's past its TTL (the
 * raw token needed to redeem one was never stored to begin with — see each entity's own
 * tokenHash docblock). An expired activation token can't be redeemed either, but for two
 * weeks its row still lets the activation page tell an expired or already-used link from
 * an unknown one, and offer a renewal request (GitHub issue #169).
 * A bulk DQL DELETE, not an entity-by-entity load+remove+flush loop: neither entity
 * carries relations that need Doctrine's own cascade/event handling, so there's no
 * reason to pay the cost of hydrating every expired row first.
 *
 * Also prunes InviteRecord rows past their own, separate retention window
 * (InviteRecord::RETENTION_DAYS, counted from createdAt — deliberately not tied to
 * ActivationToken's 24h expiresAt, since the whole point of this table is to stay
 * readable for admins well past that token's own deletion — see InviteRecord's own
 * docblock).
 */
#[AsCommand(name: 'app:cleanup-expired-tokens', description: 'Delete expired password-reset tokens, and activation tokens and invite records past their retention windows')]
class CleanupExpiredTokensCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->entityManager->getFilters()->isEnabled(\App\Doctrine\CompanyFilter::NAME)) {
            $this->entityManager->getFilters()->disable(\App\Doctrine\CompanyFilter::NAME);
        }

        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        $activationTokenCutoff = $now->modify(sprintf('-%d days', ActivationToken::RETENTION_DAYS_AFTER_EXPIRY));
        $deletedActivationTokens = $this->entityManager->createQueryBuilder()
            ->delete(ActivationToken::class, 't')
            ->where('t.expiresAt < :cutoff')
            ->setParameter('cutoff', $activationTokenCutoff)
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

        $inviteRecordCutoff = $now->modify(sprintf('-%d days', InviteRecord::RETENTION_DAYS));
        $deletedInviteRecords = $this->entityManager->createQueryBuilder()
            ->delete(InviteRecord::class, 'i')
            ->where('i.createdAt < :cutoff')
            ->setParameter('cutoff', $inviteRecordCutoff)
            ->getQuery()
            ->execute();
        \assert(\is_int($deletedInviteRecords));

        $io->success(sprintf(
            'Deleted %d activation token(s) past their retention window, %d expired password-reset token(s), and %d invite record(s) past their retention window.',
            $deletedActivationTokens,
            $deletedPasswordResetTokens,
            $deletedInviteRecords,
        ));

        return Command::SUCCESS;
    }
}
