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
 * delete-and-recreate logic against that real fixture; it doesn't attempt
 * to verify the ciphertext itself decrypts correctly (that's exactly what
 * generate-demo-fixture.mjs's own real-crypto verification pass already
 * covers, offline, before the fixture is ever committed).
 */
class ResetDemoDataCommandTest extends ApiTestCase
{
    public function testFirstRunCreatesEveryLocalePairWithA3CycleHistory(): void
    {
        static::createClient();
        $this->runCommand();

        foreach (['en' => '', 'ru' => '-ru', 'lv' => '-lv', 'es' => '-es'] as $suffix) {
            $employee = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => "demo-employee{$suffix}@example.com"]);
            $manager = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => "demo-manager{$suffix}@example.com"]);
            self::assertNotNull($employee, "employee{$suffix}");
            self::assertNotNull($manager, "manager{$suffix}");
            self::assertTrue($employee->isDemo());
            self::assertTrue($manager->isDemo());

            $anketas = $this->anketasForPair($employee, $manager);
            self::assertCount(3, $anketas, "expected 3 cycles for locale suffix \"{$suffix}\"");

            $archivedCount = 0;
            $currentCount = 0;
            foreach ($anketas as $anketa) {
                if ($anketa->isArchived()) {
                    ++$archivedCount;
                    self::assertNotNull($anketa->getEmployeeBlob());
                    self::assertNotNull($anketa->getManagerBlob());
                    self::assertTrue($anketa->isPublished($employee));
                    self::assertTrue($anketa->isPublished($manager));
                } else {
                    ++$currentCount;
                    // The current cycle is deliberately left unfilled — see
                    // ResetDemoDataCommand's own docblock.
                    self::assertNull($anketa->getEmployeeBlob());
                }
            }
            self::assertSame(2, $archivedCount);
            self::assertSame(1, $currentCount);

            $goals = $this->entityManager()->getRepository(Goal::class)->findBy(['anketa' => $anketas]);
            self::assertCount(3, $goals, 'one Goal row per cycle, sharing a goalUuid');
            $goalUuids = array_unique(array_map(static fn (Goal $g) => $g->getGoalUuid(), $goals));
            self::assertCount(1, $goalUuids);
        }
    }

    public function testRunningTwiceIsIdempotent(): void
    {
        static::createClient();
        $this->runCommand();
        $this->runCommand();

        $employees = $this->entityManager()->getRepository(User::class)->findBy(['email' => 'demo-employee@example.com']);
        self::assertCount(1, $employees);

        $manager = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-manager@example.com']);
        self::assertNotNull($manager);
        $anketas = $this->anketasForPair($employees[0], $manager);
        self::assertCount(3, $anketas, 'a second reset must not duplicate the 3 cycles');
    }

    public function testResetRestoresContentAfterVandalismAndUnblocksTheAccount(): void
    {
        static::createClient();
        $this->runCommand();

        $employee = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-employee@example.com']);
        $manager = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-manager@example.com']);
        self::assertNotNull($employee);
        self::assertNotNull($manager);
        $employee->setBlocked(true);

        // Vandalism: corrupt one archived cycle's comments version, and
        // delete the current (unarchived) cycle outright.
        $anketas = $this->anketasForPair($employee, $manager);
        $archived = array_values(array_filter($anketas, static fn (Anketa $a) => $a->isArchived()));
        $current = array_values(array_filter($anketas, static fn (Anketa $a) => !$a->isArchived()));
        self::assertNotEmpty($archived);
        self::assertNotEmpty($current);

        $this->entityManager()->createQueryBuilder()
            ->update(Anketa::class, 'a')
            ->set('a.commentsVersion', ':v')
            ->where('a.id = :id')
            ->setParameter('v', 999)
            ->setParameter('id', $archived[0]->getId())
            ->getQuery()
            ->execute();
        $carriedGoal = $this->entityManager()->getRepository(Goal::class)->findOneBy(['anketa' => $current[0]]);
        self::assertNotNull($carriedGoal);
        $this->entityManager()->remove($carriedGoal);
        $this->entityManager()->remove($current[0]);
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $this->runCommand();

        $employeeAfter = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-employee@example.com']);
        $managerAfter = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-manager@example.com']);
        self::assertNotNull($employeeAfter);
        self::assertNotNull($managerAfter);
        self::assertFalse($employeeAfter->isBlocked());

        $anketasAfter = $this->anketasForPair($employeeAfter, $managerAfter);
        self::assertCount(3, $anketasAfter, 'the deleted current cycle must come back, and no extras left behind');
        foreach ($anketasAfter as $anketa) {
            if ($anketa->isArchived()) {
                self::assertSame(1, $anketa->getCommentsVersion(), 'the corrupted version must be restored, not left at 999');
            }
        }
    }

    private function runCommand(): void
    {
        $command = new ResetDemoDataCommand($this->entityManager(), $this->singleCompanyProvider());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode, $tester->getDisplay());
    }

    /** @return Anketa[] */
    private function anketasForPair(User $employee, User $manager): array
    {
        /** @var Anketa[] $anketas */
        $anketas = $this->entityManager()->createQueryBuilder()
            ->select('anketa')
            ->from(Anketa::class, 'anketa')
            ->where('anketa.employee = :employee')
            ->andWhere('anketa.manager = :manager')
            ->setParameter('employee', $employee)
            ->setParameter('manager', $manager)
            ->getQuery()
            ->getResult();

        return $anketas;
    }
}
