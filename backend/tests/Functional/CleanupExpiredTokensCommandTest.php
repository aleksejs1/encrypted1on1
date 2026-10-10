<?php

namespace App\Tests\Functional;

use App\Command\CleanupExpiredTokensCommand;
use App\Entity\ActivationToken;
use App\Entity\InviteRecord;
use App\Entity\PasswordResetToken;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

class CleanupExpiredTokensCommandTest extends ApiTestCase
{
    /**
     * Password-reset tokens go as soon as they expire; activation tokens are kept
     * ActivationToken::RETENTION_DAYS_AFTER_EXPIRY days past expiry first (GitHub issue
     * #169), so a recently expired one survives.
     */
    public function testDeletesExpiredResetTokensAndActivationTokensPastTheirRetention(): void
    {
        static::createClient();
        $company = $this->singleCompanyProvider()->get();

        // Distinct random hashes per run — tokenHash is uniquely indexed, and a literal
        // constant would collide on a rerun against a database that isn't recreated
        // from scratch (composer test always does, a bare phpunit invocation might not).
        $prunedActivationHash = bin2hex(random_bytes(32));
        $expiredActivationHash = bin2hex(random_bytes(32));
        $freshActivationHash = bin2hex(random_bytes(32));
        $expiredResetHash = bin2hex(random_bytes(32));
        $freshResetHash = bin2hex(random_bytes(32));

        $prunedActivation = new ActivationToken($prunedActivationHash, $this->uniqueEmail('cleanup-pruned-activation'), $company, false, new \DateTimeImmutable(sprintf('-%d days -1 minute', ActivationToken::RETENTION_DAYS_AFTER_EXPIRY)));
        $expiredActivation = new ActivationToken($expiredActivationHash, $this->uniqueEmail('cleanup-expired-activation'), $company, false, new \DateTimeImmutable('-1 minute'));
        $freshActivation = new ActivationToken($freshActivationHash, $this->uniqueEmail('cleanup-fresh-activation'), $company, false, new \DateTimeImmutable('+1 hour'));
        $expiredReset = new PasswordResetToken($expiredResetHash, $this->uniqueEmail('cleanup-expired-reset'), new \DateTimeImmutable('-1 minute'));
        $freshReset = new PasswordResetToken($freshResetHash, $this->uniqueEmail('cleanup-fresh-reset'), new \DateTimeImmutable('+1 hour'));

        $this->entityManager()->persist($prunedActivation);
        $this->entityManager()->persist($expiredActivation);
        $this->entityManager()->persist($freshActivation);
        $this->entityManager()->persist($expiredReset);
        $this->entityManager()->persist($freshReset);
        $this->entityManager()->flush();

        $command = new CleanupExpiredTokensCommand($this->entityManager());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode, $tester->getDisplay());

        self::assertNull($this->entityManager()->getRepository(ActivationToken::class)->findOneBy(['tokenHash' => $prunedActivationHash]), 'an activation token past its retention window must be deleted');
        self::assertNotNull($this->entityManager()->getRepository(ActivationToken::class)->findOneBy(['tokenHash' => $expiredActivationHash]), 'a recently expired activation token must survive its retention window');
        self::assertNotNull($this->entityManager()->getRepository(ActivationToken::class)->findOneBy(['tokenHash' => $freshActivationHash]), 'a still-usable activation token must survive');
        self::assertNull($this->entityManager()->getRepository(PasswordResetToken::class)->findOneBy(['tokenHash' => $expiredResetHash]), 'an expired password-reset token must be deleted');
        self::assertNotNull($this->entityManager()->getRepository(PasswordResetToken::class)->findOneBy(['tokenHash' => $freshResetHash]), 'a still-usable password-reset token must survive');
    }

    /**
     * InviteRecord's own retention window (InviteRecord::RETENTION_DAYS) is independent
     * of ActivationToken's 24h expiresAt — deliberately keyed off createdAt instead, so
     * this needs its own scenario rather than reusing the expiresAt-based one above.
     */
    public function testDeletesOnlyInviteRecordsPastTheirRetentionWindow(): void
    {
        static::createClient();
        $company = $this->singleCompanyProvider()->get();

        $oldId = Uuid::v7()->toRfc4122();
        $freshId = Uuid::v7()->toRfc4122();
        $old = new InviteRecord($oldId, $this->uniqueEmail('cleanup-old-invite'), $company, null, new \DateTimeImmutable('+1 day'));
        $fresh = new InviteRecord($freshId, $this->uniqueEmail('cleanup-fresh-invite'), $company, null, new \DateTimeImmutable('+1 day'));
        $this->entityManager()->persist($old);
        $this->entityManager()->persist($fresh);
        $this->entityManager()->flush();

        // createdAt is set to "now" by the constructor — backdate the old row directly
        // via SQL, the same way this suite already treats createdAt as immutable-after-
        // construction elsewhere (no setter exists on the entity, deliberately).
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE invite_records SET createdAt = :cutoff WHERE id = :id',
            ['cutoff' => (new \DateTimeImmutable(sprintf('-%d days -1 hour', InviteRecord::RETENTION_DAYS)))->format('Y-m-d H:i:s'), 'id' => $oldId],
        );

        $command = new CleanupExpiredTokensCommand($this->entityManager());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode, $tester->getDisplay());

        // A bulk DQL DELETE bypasses the UnitOfWork entirely, so the identity map still
        // holds $old/$fresh as "managed" — EntityManager::find() would return that stale
        // cached instance instead of re-querying, exactly the gap this clear() closes.
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(InviteRecord::class, $oldId), 'an invite record past its retention window must be deleted');
        self::assertNotNull($this->entityManager()->find(InviteRecord::class, $freshId), 'a recent invite record must survive');
    }
}
