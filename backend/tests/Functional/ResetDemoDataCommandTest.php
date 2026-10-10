<?php

namespace App\Tests\Functional;

use App\Account\AccountDeleter;
use App\Command\ResetDemoDataCommand;
use App\Entity\Anketa;
use App\Entity\AnketaPrivateNote;
use App\Entity\Goal;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

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
    /**
     * fixtureLocaleSuffixes() (used by the test below) derives its expected
     * locale set from the fixture's own keys — real for that test's purpose
     * (checking the command's handling of whatever's actually in the
     * fixture), but on its own that check can never catch a locale being
     * added to SUPPORTED_LOCALES and forgotten in the fixture, the exact gap
     * `de`/`fr` briefly sat in as `null` in `frontend/src/demo.ts`. This
     * test is the one that actually closes it, by cross-checking against
     * the canonical locale list instead.
     */
    public function testFixtureCoversEverySupportedLocale(): void
    {
        $fixtureLocales = array_keys($this->loadFixture()['locales']);
        sort($fixtureLocales);
        $supportedLocales = User::SUPPORTED_LOCALES;
        sort($supportedLocales);

        self::assertSame($supportedLocales, $fixtureLocales, 'backend/fixtures/demo-seed.json must have demo content for every locale in User::SUPPORTED_LOCALES — regenerate it via frontend/scripts/generate-demo-fixture.mjs after adding a new locale there.');
    }

    public function testFirstRunCreatesEveryLocalePairWithA3CycleHistory(): void
    {
        static::createClient();
        $this->runResetDemoDataCommand();

        foreach ($this->loadFixture()['locales'] as $localeCode => $data) {
            // Read straight from the fixture's own employee.email/manager.email
            // — the same fields ResetDemoDataCommand itself keys account
            // lookup on — rather than reconstructing an expected email from
            // the locale code by convention, so this test still catches a
            // fixture entry whose stored email doesn't actually match that
            // convention, not just one whose locale key is missing.
            $employee = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => $data['employee']['email']]);
            $manager = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => $data['manager']['email']]);
            self::assertNotNull($employee, "employee for locale \"{$localeCode}\"");
            self::assertNotNull($manager, "manager for locale \"{$localeCode}\"");
            self::assertTrue($employee->isDemo());
            self::assertTrue($manager->isDemo());

            $anketas = $this->anketasForPair($employee, $manager);
            self::assertCount(3, $anketas, "expected 3 cycles for locale \"{$localeCode}\"");

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
                    // What it does have: the topic the pair didn't get to last
                    // time, carried forward (GitHub issue #206).
                    self::assertNotNull($anketa->getTopicsBlob(), "carried topic for locale \"{$localeCode}\"");
                }
                if ($anketa->isArchived()) {
                    self::assertNotNull($anketa->getTopicsBlob());
                    self::assertGreaterThan(0, $anketa->getTopicsVersion());
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

    public function testFirstRunSetsTheSeededDisplayNameForEachAccount(): void
    {
        static::createClient();
        $this->runResetDemoDataCommand();

        $employee = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-employee@example.com']);
        $manager = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-manager@example.com']);
        self::assertNotNull($employee);
        self::assertNotNull($manager);
        self::assertSame('Alex Morgan', $employee->getDisplayName());
        self::assertSame('Jordan Blake', $manager->getDisplayName());
    }

    public function testResetRestoresADisplayNameAVisitorEdited(): void
    {
        static::createClient();
        $this->runResetDemoDataCommand();

        $employee = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-employee@example.com']);
        self::assertNotNull($employee);
        $employee->setDisplayName('Something Else Entirely');
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $this->runResetDemoDataCommand();

        $employeeAfter = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-employee@example.com']);
        self::assertNotNull($employeeAfter);
        self::assertSame('Alex Morgan', $employeeAfter->getDisplayName());
    }

    /**
     * The fixture's ciphertext names its authors by user id, so the accounts must
     * carry exactly those ids for the page to show a name rather than a raw id.
     */
    public function testAccountsGetTheIdsTheFixtureWasGeneratedWith(): void
    {
        static::createClient();
        $this->runResetDemoDataCommand();

        foreach ($this->loadFixture()['locales'] as $localeCode => $data) {
            foreach (['employee', 'manager'] as $role) {
                $user = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => $data[$role]['email']]);
                self::assertNotNull($user);
                self::assertSame($data[$role]['id'], $user->getId(), "{$role} for locale \"{$localeCode}\"");
            }
        }
    }

    public function testAnAccountCreatedUnderAnotherIdIsReplaced(): void
    {
        $client = static::createClient();
        $outsiderId = $this->activateUser($client, $this->uniqueEmail('demo-outsider'))['id'];
        $fixture = $this->loadFixture()['locales']['en'];
        // What a database looks like after a run of this command from before it set
        // the ids: the demo emails taken, with history, under ids of their own.
        $this->runResetDemoDataCommand();
        $connection = $this->entityManager()->getConnection();
        $oldEmployeeId = Uuid::v7()->toRfc4122();
        $oldManagerId = Uuid::v7()->toRfc4122();
        $connection->executeStatement('DELETE FROM goals WHERE author_id = ?', [$fixture['employee']['id']]);
        $connection->executeStatement('DELETE FROM anketas WHERE employee_id = ?', [$fixture['employee']['id']]);
        $connection->executeStatement('UPDATE users SET id = ? WHERE id = ?', [$oldEmployeeId, $fixture['employee']['id']]);
        $connection->executeStatement('UPDATE users SET id = ? WHERE id = ?', [$oldManagerId, $fixture['manager']['id']]);
        $this->entityManager()->clear();
        $oldEmployee = $this->entityManager()->getRepository(User::class)->find($oldEmployeeId);
        $oldManager = $this->entityManager()->getRepository(User::class)->find($oldManagerId);
        self::assertNotNull($oldEmployee);
        self::assertNotNull($oldManager);
        $outsider = $this->entityManager()->getRepository(User::class)->find($outsiderId);
        self::assertNotNull($outsider);
        $this->entityManager()->persist(new Anketa($oldEmployee, $oldManager, new \DateTimeImmutable(), 'sk-e', 'sk-m', 14));
        // Whoever holds a demo email under another id may be a real person: their 1:1s
        // with anyone else are left to the account deletion, not hard-deleted.
        $withOutsider = new Anketa($oldEmployee, $outsider, new \DateTimeImmutable(), 'sk-e', 'sk-o', 14);
        $this->entityManager()->persist($withOutsider);
        $this->entityManager()->flush();
        $withOutsiderId = $withOutsider->getId();
        $this->entityManager()->clear();

        $this->runResetDemoDataCommand();
        $this->runResetDemoDataCommand();
        $this->entityManager()->clear();

        $employees = $this->entityManager()->getRepository(User::class)->findBy(['email' => $fixture['employee']['email']]);
        self::assertCount(1, $employees);
        self::assertSame($fixture['employee']['id'], $employees[0]->getId());
        $manager = $this->entityManager()->getRepository(User::class)->find($fixture['manager']['id']);
        self::assertNotNull($manager);
        self::assertSame($fixture['manager']['email'], $manager->getEmail());
        self::assertCount(3, $this->anketasForPair($employees[0], $manager));

        $old = $this->entityManager()->getRepository(User::class)->find($oldEmployeeId);
        self::assertNotNull($old, 'deleted like any account: anonymized in place, not removed');
        self::assertNotNull($old->getDeletedAt());
        $left = $this->entityManager()->getRepository(Anketa::class)->findBy(['employee' => $old]);
        self::assertSame([$withOutsiderId], array_map(static fn (Anketa $a) => $a->getId(), $left), 'its seeded history must not stay behind');

        // Not the reset's to clean up, so removed here.
        $connection->executeStatement('DELETE FROM anketas WHERE id = ?', [$withOutsiderId]);
    }

    public function testAnAccountAVisitorDeletedIsRestoredUnderTheSameId(): void
    {
        static::createClient();
        $this->runResetDemoDataCommand();
        $fixture = $this->loadFixture()['locales']['en'];

        $employee = $this->entityManager()->getRepository(User::class)->find($fixture['employee']['id']);
        self::assertNotNull($employee);
        $accountDeleter = self::getContainer()->get(AccountDeleter::class);
        \assert($accountDeleter instanceof AccountDeleter);
        $accountDeleter->delete($employee);
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $this->runResetDemoDataCommand();
        $this->entityManager()->clear();

        $restored = $this->entityManager()->getRepository(User::class)->find($fixture['employee']['id']);
        self::assertNotNull($restored);
        self::assertSame($fixture['employee']['email'], $restored->getEmail());
        self::assertNull($restored->getDeletedAt());
        self::assertFalse($restored->isBlocked());
        self::assertSame('Alex Morgan', $restored->getDisplayName());
        $manager = $this->entityManager()->getRepository(User::class)->find($fixture['manager']['id']);
        self::assertNotNull($manager);
        self::assertCount(3, $this->anketasForPair($restored, $manager));
    }

    public function testRunningTwiceIsIdempotent(): void
    {
        static::createClient();
        $this->runResetDemoDataCommand();
        $this->runResetDemoDataCommand();

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
        $this->runResetDemoDataCommand();

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

        $this->runResetDemoDataCommand();

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

    /**
     * A visitor can end up with the pair's roles the other way round: a 1:1 created by
     * hand that way, or the next one swapped at archive (GitHub issue #254). The reset
     * removes those too, or they'd stay beside the reseeded chain for good.
     */
    public function testResetRemovesAnAnketaWithThePairsRolesSwapped(): void
    {
        static::createClient();
        $this->runResetDemoDataCommand();

        $employee = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-employee@example.com']);
        $manager = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-manager@example.com']);
        self::assertNotNull($employee);
        self::assertNotNull($manager);
        $this->entityManager()->persist(new Anketa(
            employee: $manager,
            manager: $employee,
            meetingDate: new \DateTimeImmutable('+7 days'),
            employeeSealedKey: 'sealed-m',
            managerSealedKey: 'sealed-e',
            periodicityDays: 14,
        ));
        $this->entityManager()->flush();
        self::assertCount(1, $this->anketasForPair($manager, $employee));
        $this->entityManager()->clear();

        $this->runResetDemoDataCommand();

        $employeeAfter = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-employee@example.com']);
        $managerAfter = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-manager@example.com']);
        self::assertNotNull($employeeAfter);
        self::assertNotNull($managerAfter);
        self::assertCount(0, $this->anketasForPair($managerAfter, $employeeAfter));
        self::assertCount(3, $this->anketasForPair($employeeAfter, $managerAfter));
    }

    /**
     * A demo visitor's private notes (GitHub issue #132 §5.5) reference the anketas the
     * reset deletes, so they must go first: a real database's foreign key rejects
     * deleting an anketa that still has notes (SQLite here runs without foreign keys, so
     * this only fails for real on MySQL — run it there too).
     */
    public function testResetRemovesPrivateNotesOnTheDeletedAnketas(): void
    {
        static::createClient();
        $this->runResetDemoDataCommand();

        $employee = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-employee@example.com']);
        $manager = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-manager@example.com']);
        self::assertNotNull($employee);
        self::assertNotNull($manager);
        foreach ($this->anketasForPair($employee, $manager) as $anketa) {
            $this->entityManager()->persist(new AnketaPrivateNote($anketa, $employee, 'visitor-key', 'visitor-notes'));
            $this->entityManager()->persist(new AnketaPrivateNote($anketa, $manager, 'visitor-key', 'visitor-notes'));
        }
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $this->runResetDemoDataCommand();

        self::assertSame(0, (int) $this->entityManager()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM anketa_private_notes WHERE notesBlob = 'visitor-notes'",
        ));
        $employeeAfter = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-employee@example.com']);
        $managerAfter = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-manager@example.com']);
        self::assertNotNull($employeeAfter);
        self::assertNotNull($managerAfter);
        self::assertCount(3, $this->anketasForPair($employeeAfter, $managerAfter));
    }

    /**
     * Without foreign keys (SQLite), a notes autosave landing between the reset's notes
     * delete and its flush leaves a note whose anketa is gone. The next run must remove
     * it: the demo keypair is restored every run, so every later visitor could read it.
     */
    public function testResetRemovesADemoAccountsNoteLeftWithoutItsAnketa(): void
    {
        static::createClient();
        $this->runResetDemoDataCommand();

        $employee = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-employee@example.com']);
        $manager = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'demo-manager@example.com']);
        self::assertNotNull($employee);
        self::assertNotNull($manager);
        $gone = new Anketa($employee, $manager, new \DateTimeImmutable('+3 days'), 'sealed-e', 'sealed-m', 30);
        $this->entityManager()->persist($gone);
        $this->entityManager()->persist(new AnketaPrivateNote($gone, $employee, 'visitor-key', 'orphaned-notes'));
        $this->entityManager()->flush();
        $connection = $this->entityManager()->getConnection();
        if (0 === $connection->executeStatement('DELETE FROM anketas WHERE id = ?', [$gone->getId()])) {
            self::markTestSkipped('This database enforces the foreign key, so the note can never outlive its anketa.');
        }
        $this->entityManager()->clear();

        $this->runResetDemoDataCommand();

        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM anketa_private_notes WHERE notesBlob = 'orphaned-notes'"));
    }

    /**
     * A visitor can create a 1:1 with any other user of the company, not only with the
     * demo counterpart. The reset removes it in either role, with its goal and with the
     * other user's own private notes on it, and leaves that user's other anketas alone.
     * SQLite here runs without foreign keys, so the order of the deletes is only
     * checked for real on MySQL — run it there too.
     */
    public function testResetRemovesAnAnketaADemoAccountHasWithAnotherUser(): void
    {
        $client = static::createClient();
        $this->runResetDemoDataCommand();

        $otherId = $this->activateUser($client, $this->uniqueEmail('demo-outsider'))['id'];
        $thirdId = $this->activateUser($client, $this->uniqueEmail('demo-bystander'))['id'];
        $users = $this->entityManager()->getRepository(User::class);
        $employee = $users->findOneBy(['email' => 'demo-employee@example.com']);
        $manager = $users->findOneBy(['email' => 'demo-manager@example.com']);
        $other = $users->find($otherId);
        $third = $users->find($thirdId);
        self::assertNotNull($employee);
        self::assertNotNull($manager);
        self::assertNotNull($other);
        self::assertNotNull($third);

        $asEmployee = new Anketa($employee, $other, new \DateTimeImmutable('+3 days'), 'sealed-e', 'sealed-o', 30);
        $asManager = new Anketa($other, $manager, new \DateTimeImmutable('+4 days'), 'sealed-o', 'sealed-m', 30);
        $unrelated = new Anketa($other, $third, new \DateTimeImmutable('+5 days'), 'sealed-o', 'sealed-t', 30);
        foreach ([$asEmployee, $asManager, $unrelated] as $anketa) {
            $this->entityManager()->persist($anketa);
            $this->entityManager()->persist(new AnketaPrivateNote($anketa, $other, 'outsider-key', 'outsider-notes'));
        }
        $this->entityManager()->persist(new Goal(Uuid::v7()->toRfc4122(), $asEmployee, $employee, 'Visitor goal', null, null));
        $this->entityManager()->flush();
        $visitorMadeIds = [$asEmployee->getId(), $asManager->getId()];
        $unrelatedId = $unrelated->getId();
        $this->entityManager()->clear();

        $this->runResetDemoDataCommand();

        $connection = $this->entityManager()->getConnection();
        foreach ($visitorMadeIds as $id) {
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM anketas WHERE id = ?', [$id]));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM anketa_private_notes WHERE anketa_id = ?', [$id]));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM goals WHERE anketa_id = ?', [$id]));
        }
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM anketas WHERE id = ?', [$unrelatedId]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM anketa_private_notes WHERE anketa_id = ?', [$unrelatedId]));
        // Not the reset's to clean up, so removed here.
        $connection->executeStatement('DELETE FROM anketa_private_notes WHERE anketa_id = ?', [$unrelatedId]);
        $connection->executeStatement('DELETE FROM anketas WHERE id = ?', [$unrelatedId]);

        $employeeAfter = $users->findOneBy(['email' => 'demo-employee@example.com']);
        $managerAfter = $users->findOneBy(['email' => 'demo-manager@example.com']);
        self::assertNotNull($employeeAfter);
        self::assertNotNull($managerAfter);
        self::assertCount(3, $this->anketasForPair($employeeAfter, $managerAfter));
    }

    private function runResetDemoDataCommand(): void
    {
        $accountDeleter = self::getContainer()->get(AccountDeleter::class);
        \assert($accountDeleter instanceof AccountDeleter);
        $command = new ResetDemoDataCommand($this->entityManager(), $this->singleCompanyProvider(), $accountDeleter);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode, $tester->getDisplay());
    }

    /** @return array{locales: array<string, array{employee: array{id: string, email: string}, manager: array{id: string, email: string}}>} */
    private function loadFixture(): array
    {
        $fixturePath = \dirname(__DIR__, 2).'/fixtures/demo-seed.json';

        /** @var array{locales: array<string, array{employee: array{id: string, email: string}, manager: array{id: string, email: string}}>} $fixture */
        $fixture = json_decode((string) file_get_contents($fixturePath), true, flags: \JSON_THROW_ON_ERROR);

        return $fixture;
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
