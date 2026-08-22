<?php

namespace App\Tests\Functional;

use App\Command\CleanupExpiredTokensCommand;
use App\Entity\ActivationToken;
use App\Entity\PasswordResetToken;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CleanupExpiredTokensCommandTest extends ApiTestCase
{
    public function testDeletesOnlyExpiredTokens(): void
    {
        static::createClient();
        $company = $this->singleCompanyProvider()->get();

        // Distinct random hashes per run — tokenHash is uniquely indexed, and a literal
        // constant would collide on a rerun against a database that isn't recreated
        // from scratch (composer test always does, a bare phpunit invocation might not).
        $expiredActivationHash = bin2hex(random_bytes(32));
        $freshActivationHash = bin2hex(random_bytes(32));
        $expiredResetHash = bin2hex(random_bytes(32));
        $freshResetHash = bin2hex(random_bytes(32));

        $expiredActivation = new ActivationToken($expiredActivationHash, $this->uniqueEmail('cleanup-expired-activation'), $company, false, new \DateTimeImmutable('-1 minute'));
        $freshActivation = new ActivationToken($freshActivationHash, $this->uniqueEmail('cleanup-fresh-activation'), $company, false, new \DateTimeImmutable('+1 hour'));
        $expiredReset = new PasswordResetToken($expiredResetHash, $this->uniqueEmail('cleanup-expired-reset'), new \DateTimeImmutable('-1 minute'));
        $freshReset = new PasswordResetToken($freshResetHash, $this->uniqueEmail('cleanup-fresh-reset'), new \DateTimeImmutable('+1 hour'));

        $this->entityManager()->persist($expiredActivation);
        $this->entityManager()->persist($freshActivation);
        $this->entityManager()->persist($expiredReset);
        $this->entityManager()->persist($freshReset);
        $this->entityManager()->flush();

        $command = new CleanupExpiredTokensCommand($this->entityManager());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode, $tester->getDisplay());

        self::assertNull($this->entityManager()->getRepository(ActivationToken::class)->findOneBy(['tokenHash' => $expiredActivationHash]), 'an expired activation token must be deleted');
        self::assertNotNull($this->entityManager()->getRepository(ActivationToken::class)->findOneBy(['tokenHash' => $freshActivationHash]), 'a still-usable activation token must survive');
        self::assertNull($this->entityManager()->getRepository(PasswordResetToken::class)->findOneBy(['tokenHash' => $expiredResetHash]), 'an expired password-reset token must be deleted');
        self::assertNotNull($this->entityManager()->getRepository(PasswordResetToken::class)->findOneBy(['tokenHash' => $freshResetHash]), 'a still-usable password-reset token must survive');
    }
}
