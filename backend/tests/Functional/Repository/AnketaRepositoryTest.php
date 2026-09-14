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
