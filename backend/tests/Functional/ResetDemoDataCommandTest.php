<?php

namespace App\Tests\Functional;

use App\Command\ResetDemoDataCommand;
use App\Entity\Anketa;
use App\Entity\Goal;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The fixture (backend/fixtures/demo-seed.json) is committed, real seed
 * data — see frontend/scripts/generate-demo-fixture.mjs for how it was
 * generated and private/demo-mode-plan.md (not tracked in git) for the
 * full design. This test only exercises the command's own idempotent
 * find-or-create/reset logic against that real fixture; it doesn't attempt
 * to verify the ciphertext itself decrypts correctly (that's exactly what
 * generate-demo-fixture.mjs's own real-crypto verification pass already
 * covers, offline, before the fixture is ever committed).
 */
class ResetDemoDataCommandTest extends ApiTestCase
{
    public function testFirstRunCreatesTheDemoAccountsAnketaAndGoal(): void
    {
        static::createClient();
        $this->runCommand();

        $employee = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-employee@example.com']);
        $manager = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-manager@example.com']);
        self::assertNotNull($employee);
        self::assertNotNull($manager);
        self::assertTrue($employee->isDemo());
        self::assertTrue($manager->isDemo());

        $anketa = $this->findAnketaFor($employee);
        self::assertNotNull($anketa);
        self::assertNotNull($anketa->getEmployeeBlob());
        self::assertNotNull($anketa->getManagerBlob());
        self::assertTrue($anketa->isPublished($employee));
        self::assertTrue($anketa->isPublished($manager));
        self::assertFalse($anketa->isArchived());

        $goals = $this->entityManager()->getRepository(Goal::class)->findBy(['anketa' => $anketa]);
        self::assertCount(1, $goals);
    }

    public function testRunningTwiceIsIdempotent(): void
    {
        static::createClient();
        $this->runCommand();
        $this->runCommand();

        $employees = $this->entityManager()->getRepository(User::class)->findBy(['email' => 'demo-employee@example.com']);
        self::assertCount(1, $employees);

        $employee = $employees[0];
        $anketas = $this->entityManager()->createQueryBuilder()
            ->select('anketa')
            ->from(Anketa::class, 'anketa')
            ->where('anketa.employee = :employee')
            ->setParameter('employee', $employee)
            ->getQuery()
            ->getResult();
        self::assertCount(1, $anketas);
    }

    public function testResetRestoresContentAfterVandalismAndUnblocksTheAccount(): void
    {
        static::createClient();
        $this->runCommand();

        $employee = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-employee@example.com']);
        self::assertNotNull($employee);
        $employee->setBlocked(true);

        $anketa = $this->findAnketaFor($employee);
        self::assertNotNull($anketa);
        $anketaId = $anketa->getId();
        $this->entityManager()->createQueryBuilder()
            ->update(Anketa::class, 'a')
            ->set('a.commentsVersion', ':v')
            ->where('a.id = :id')
            ->setParameter('v', 999)
            ->setParameter('id', $anketaId)
            ->getQuery()
            ->execute();
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $this->runCommand();

        $employeeAfter = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-employee@example.com']);
        self::assertNotNull($employeeAfter);
        self::assertFalse($employeeAfter->isBlocked());

        $anketaAfter = $this->entityManager()->getRepository(Anketa::class)->find($anketaId);
        self::assertNotNull($anketaAfter);
        self::assertSame(1, $anketaAfter->getCommentsVersion());
    }

    private function runCommand(): void
    {
        $command = new ResetDemoDataCommand($this->entityManager());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode, $tester->getDisplay());
    }

    private function findAnketaFor(User $employee): ?Anketa
    {
        /** @var Anketa|null $anketa */
        $anketa = $this->entityManager()->createQueryBuilder()
            ->select('anketa')
            ->from(Anketa::class, 'anketa')
            ->where('anketa.employee = :employee')
            ->setParameter('employee', $employee)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $anketa;
    }
}
