<?php

namespace App\Tests\Functional\Repository;

use App\Entity\Anketa;
use App\Entity\Goal;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

class AnketaRepositoryTest extends ApiTestCase
{
    /**
     * @return array{0: KernelBrowser, 1: array{id: string, email: string, isAdmin: bool},
     *     2: KernelBrowser, 3: array{id: string, email: string, isAdmin: bool}}
     */
    private function makePair(string $label): array
    {
        $employeeClient = static::createClient();
        $employee = $this->activateUser($employeeClient, $this->uniqueEmail("repo-{$label}-emp"));
        $managerClient = $this->secondClient();
        $manager = $this->activateUser($managerClient, $this->uniqueEmail("repo-{$label}-mgr"));

        return [$employeeClient, $employee, $managerClient, $manager];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{status: int, json: mixed}
     */
    private function createAnketaAsEmployee(KernelBrowser $employeeClient, string $counterpartId, array $overrides = []): array
    {
        $body = array_merge([
            'counterpartId' => $counterpartId,
            'myRole' => 'employee',
            'meetingDate' => (new \DateTimeImmutable('+1 day'))->format(\DateTimeImmutable::ATOM),
            'mySealedKey' => str_repeat('e', 44),
            'counterpartSealedKey' => str_repeat('m', 44),
            'periodicityDays' => 30,
        ], $overrides);

        $body = array_filter($body, static fn ($value) => null !== $value);

        return $this->jsonRequest($employeeClient, 'POST', '/api/anketas', $body);
    }

    public function testFindWithParticipantsAndFindAllForUser(): void
    {
        [$empClient, $employee, , $manager] = $this->makePair('find-all');
        $em = $this->entityManager();

        $anketaRepo = $em->getRepository(Anketa::class);

        $created = $this->createAnketaAsEmployee($empClient, $manager['id']);
        $anketaId = $created['json']['id'];

        $found = $anketaRepo->findWithParticipants($anketaId);
        self::assertNotNull($found);
        self::assertSame($anketaId, $found->getId());
        self::assertSame($employee['id'], $found->getEmployee()->getId());
        self::assertSame($manager['id'], $found->getManager()->getId());

        $empUser = $em->find(User::class, $employee['id']);
        $mgrUser = $em->find(User::class, $manager['id']);
        \assert($empUser instanceof User && $mgrUser instanceof User);

        $allForEmp = $anketaRepo->findAllForUser($empUser);
        self::assertCount(1, $allForEmp);
        self::assertSame($anketaId, $allForEmp[0]->getId());

        $allForMgr = $anketaRepo->findAllForUser($mgrUser);
        self::assertCount(1, $allForMgr);
        self::assertSame($anketaId, $allForMgr[0]->getId());
    }

    /**
     * GitHub issue #130: the conditional UPDATE is what stops two concurrent archive
     * requests (each holding its own still-unarchived copy of the row) from both
     * archiving it. Only the first call may win, and the loser must not overwrite it.
     */
    public function testMarkArchivedIfOpenOnlySucceedsOnce(): void
    {
        [$empClient, , , $manager] = $this->makePair('mark-archived');
        $anketaId = $this->createAnketaAsEmployee($empClient, $manager['id'])['json']['id'];

        $em = $this->entityManager();
        $anketaRepo = $em->getRepository(Anketa::class);
        $anketa = $anketaRepo->find($anketaId);
        self::assertNotNull($anketa);

        $firstArchivedAt = new \DateTimeImmutable('2026-09-01 10:00:00');
        self::assertTrue($anketaRepo->markArchivedIfOpen($anketa, $firstArchivedAt, true));
        // The same stale in-memory entity a racing request would still be holding.
        self::assertFalse($anketa->isArchived());
        self::assertFalse($anketaRepo->markArchivedIfOpen($anketa, new \DateTimeImmutable('2026-09-02 10:00:00'), false));

        $em->clear();
        $reloaded = $anketaRepo->find($anketaId);
        self::assertNotNull($reloaded);
        self::assertEquals($firstArchivedAt, $reloaded->getArchivedAt());
        self::assertTrue($reloaded->isMissed());
    }

    public function testFindMostRecentArchivedForPairReturnsNullWhenUnarchived(): void
    {
        [$empClient, $employee, , $manager] = $this->makePair('unarchived');
        $em = $this->entityManager();

        $anketaRepo = $em->getRepository(Anketa::class);

        $this->createAnketaAsEmployee($empClient, $manager['id']);

        $empUser = $em->find(User::class, $employee['id']);
        $mgrUser = $em->find(User::class, $manager['id']);
        \assert($empUser instanceof User && $mgrUser instanceof User);

        self::assertNull($anketaRepo->findMostRecentArchivedForPair($empUser, $mgrUser));
    }

    public function testFindMostRecentArchivedForPairMatchesRegardlessOfUserOrder(): void
    {
        [$empClient, $employee, , $manager] = $this->makePair('archived');
        $em = $this->entityManager();

        $anketaRepo = $em->getRepository(Anketa::class);

        $olderCreated = $this->createAnketaAsEmployee($empClient, $manager['id'], [
            'meetingDate' => (new \DateTimeImmutable('+1 day'))->format(\DateTimeImmutable::ATOM),
        ]);
        $olderId = $olderCreated['json']['id'];
        $this->jsonRequest($empClient, 'POST', "/api/anketas/{$olderId}/archive", [
            'missed' => false,
            'skipNextMeeting' => true,
        ]);

        $newerCreated = $this->createAnketaAsEmployee($empClient, $manager['id'], [
            'meetingDate' => (new \DateTimeImmutable('+14 days'))->format(\DateTimeImmutable::ATOM),
        ]);
        $newerId = $newerCreated['json']['id'];
        $this->jsonRequest($empClient, 'POST', "/api/anketas/{$newerId}/archive", [
            'missed' => false,
            'skipNextMeeting' => true,
        ]);

        $empUser = $em->find(User::class, $employee['id']);
        $mgrUser = $em->find(User::class, $manager['id']);
        \assert($empUser instanceof User && $mgrUser instanceof User);

        $archived = $anketaRepo->findMostRecentArchivedForPair($empUser, $mgrUser);
        self::assertNotNull($archived);
        self::assertSame($newerId, $archived->getId());

        $archivedReversed = $anketaRepo->findMostRecentArchivedForPair($mgrUser, $empUser);
        self::assertNotNull($archivedReversed);
        self::assertSame($newerId, $archivedReversed->getId());
    }

    public function testFindOpenForPairIgnoresOneOffs(): void
    {
        [$empClient, $employee, , $manager] = $this->makePair('open-for-pair');
        $em = $this->entityManager();
        $anketaRepo = $em->getRepository(Anketa::class);

        $empUser = $em->find(User::class, $employee['id']);
        $mgrUser = $em->find(User::class, $manager['id']);
        \assert($empUser instanceof User && $mgrUser instanceof User);

        $regularId = $this->createAnketaAsEmployee($empClient, $manager['id'])['json']['id'];
        // Created next to the open regular one, so it's a one-off (GitHub issue #111).
        $this->createAnketaAsEmployee($empClient, $manager['id']);

        $open = $anketaRepo->findOpenForPair($empUser, $mgrUser);
        $openReversed = $anketaRepo->findOpenForPair($mgrUser, $empUser);
        self::assertNotNull($open);
        self::assertNotNull($openReversed);
        self::assertSame($regularId, $open->getId());
        self::assertSame($regularId, $openReversed->getId());

        $this->jsonRequest($empClient, 'POST', "/api/anketas/{$regularId}/archive", [
            'missed' => false,
            'skipNextMeeting' => true,
        ]);
        $em->clear();

        self::assertNull($anketaRepo->findOpenForPair($empUser, $mgrUser), 'an open one-off alone is not the pair\'s chain');
    }

    /** Only reachable for a pair that forked before GitHub issue #111's fix. */
    public function testFindOpenForPairPicksTheEarliestOfSeveralOpenChainAnketas(): void
    {
        [$empClient, $employee, , $manager] = $this->makePair('open-earliest');
        $em = $this->entityManager();
        $anketaRepo = $em->getRepository(Anketa::class);

        $empUser = $em->find(User::class, $employee['id']);
        $mgrUser = $em->find(User::class, $manager['id']);
        \assert($empUser instanceof User && $mgrUser instanceof User);

        // Built directly, not via the API — create() would make the second one a one-off.
        $later = new Anketa($empUser, $mgrUser, new \DateTimeImmutable('+20 days'), 'k', 'k', 14);
        $earlier = new Anketa($empUser, $mgrUser, new \DateTimeImmutable('+5 days'), 'k', 'k', 14);
        $em->persist($later);
        $em->persist($earlier);
        $em->flush();

        $open = $anketaRepo->findOpenForPair($empUser, $mgrUser);
        self::assertNotNull($open);
        self::assertSame($earlier->getId(), $open->getId());
    }

    public function testFindMostRecentArchivedForPairIgnoresOneOffs(): void
    {
        [$empClient, $employee, , $manager] = $this->makePair('archived-one-off');
        $em = $this->entityManager();
        $anketaRepo = $em->getRepository(Anketa::class);

        $chainId = $this->createAnketaAsEmployee($empClient, $manager['id'], [
            'meetingDate' => (new \DateTimeImmutable('+1 day'))->format(\DateTimeImmutable::ATOM),
        ])['json']['id'];
        // Created next to the open chain anketa, so it's a one-off (GitHub issue #111) —
        // with a later meeting date, so a plain "most recent" lookup would pick it.
        $oneOffId = $this->createAnketaAsEmployee($empClient, $manager['id'], [
            'meetingDate' => (new \DateTimeImmutable('+14 days'))->format(\DateTimeImmutable::ATOM),
        ])['json']['id'];
        foreach ([$chainId, $oneOffId] as $id) {
            $this->jsonRequest($empClient, 'POST', "/api/anketas/{$id}/archive", [
                'missed' => false,
                'skipNextMeeting' => true,
            ]);
        }

        $empUser = $em->find(User::class, $employee['id']);
        $mgrUser = $em->find(User::class, $manager['id']);
        \assert($empUser instanceof User && $mgrUser instanceof User);

        $archived = $anketaRepo->findMostRecentArchivedForPair($empUser, $mgrUser);
        $archivedReversed = $anketaRepo->findMostRecentArchivedForPair($mgrUser, $empUser);
        self::assertNotNull($archived);
        self::assertNotNull($archivedReversed);
        self::assertSame($chainId, $archived->getId());
        self::assertSame($chainId, $archivedReversed->getId());
    }

    public function testGoalRepositoryQueries(): void
    {
        [$empClient, $employee, , $manager] = $this->makePair('goal-repo');
        $em = $this->entityManager();

        $goalRepo = $em->getRepository(Goal::class);

        self::assertSame([], $goalRepo->findByAnketasGroupedByAnketaId([]));

        $created = $this->createAnketaAsEmployee($empClient, $manager['id']);
        $anketaId = $created['json']['id'];
        $anketa = $em->find(Anketa::class, $anketaId);
        \assert($anketa instanceof Anketa);
        $empUser = $anketa->getEmployee();

        // Create in_progress goal
        $goal1 = new Goal('uuid-g1', $anketa, $empUser, 'Goal 1', null, null, Goal::STATUS_IN_PROGRESS);
        // Create achieved goal
        $goal2 = new Goal('uuid-g2', $anketa, $empUser, 'Goal 2', null, null, Goal::STATUS_ACHIEVED);
        $em->persist($goal1);
        $em->persist($goal2);
        $em->flush();

        $allGoals = $goalRepo->findByAnketa($anketa);
        self::assertCount(2, $allGoals);

        $inProgress = $goalRepo->findInProgressForAnketa($anketa);
        self::assertCount(1, $inProgress);
        self::assertSame('uuid-g1', $inProgress[0]->getGoalUuid());

        $grouped = $goalRepo->findByAnketasGroupedByAnketaId([$anketa]);
        self::assertArrayHasKey($anketaId, $grouped);
        self::assertCount(2, $grouped[$anketaId]);
    }
}
