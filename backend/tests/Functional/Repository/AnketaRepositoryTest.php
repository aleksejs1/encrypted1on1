<?php

namespace App\Tests\Functional\Repository;

use App\Entity\Anketa;
use App\Entity\Goal;
use App\Entity\User;
use App\Repository\AnketaRepository;
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

    /**
     * GitHub issue #167: the reminder job claims each anketa for its meeting day before
     * sending, so of two overlapping runs only one sends.
     */
    public function testClaimReminderSucceedsOncePerMeetingDay(): void
    {
        [$anketaRepo, $anketa] = $this->reminderAnketa('claim-once');
        $monday = new \DateTimeImmutable('2091-08-06T00:00:00Z');

        self::assertTrue($this->isDue($anketa, $monday));
        self::assertTrue($anketaRepo->claimReminder($anketa->getId(), $monday, new \DateTimeImmutable()));
        self::assertFalse($anketaRepo->claimReminder($anketa->getId(), $monday, new \DateTimeImmutable()));
        self::assertFalse($this->isDue($anketa, $monday));
    }

    public function testReleaseReminderMakesItDueAgain(): void
    {
        [$anketaRepo, $anketa] = $this->reminderAnketa('release');
        $monday = new \DateTimeImmutable('2091-08-06T00:00:00Z');
        self::assertTrue($anketaRepo->claimReminder($anketa->getId(), $monday, new \DateTimeImmutable()));

        $anketaRepo->releaseReminder($anketa->getId(), $monday, null);

        self::assertTrue($this->isDue($anketa, $monday));
    }

    /** A meeting reminded for Monday, moved, failed to send, then moved back isn't reminded twice. */
    public function testReleaseReminderRestoresThePreviousDay(): void
    {
        [$anketaRepo, $anketa] = $this->reminderAnketa('release-restore');
        $monday = new \DateTimeImmutable('2091-08-06T00:00:00Z');
        $sunday = new \DateTimeImmutable('2091-08-05T00:00:00Z');
        self::assertTrue($anketaRepo->claimReminder($anketa->getId(), $monday, new \DateTimeImmutable()));
        $anketa->reschedule($sunday);
        $this->entityManager()->flush();
        self::assertTrue($anketaRepo->claimReminder($anketa->getId(), $sunday, new \DateTimeImmutable()));

        $anketaRepo->releaseReminder($anketa->getId(), $sunday, $monday);
        $anketa->reschedule($monday);
        $this->entityManager()->flush();

        self::assertFalse($this->isDue($anketa, $monday));
    }

    /** A meeting moved since the select is skipped, not reminded with its old date. */
    public function testClaimReminderMissesAMeetingMovedToAnotherDay(): void
    {
        [$anketaRepo, $anketa] = $this->reminderAnketa('claim-moved');
        $anketa->reschedule(new \DateTimeImmutable('2091-08-08T00:00:00Z'));
        $this->entityManager()->flush();

        self::assertFalse($anketaRepo->claimReminder($anketa->getId(), new \DateTimeImmutable('2091-08-06T00:00:00Z'), new \DateTimeImmutable()));
    }

    public function testClaimReminderRefusesAnArchivedAnketa(): void
    {
        [$anketaRepo, $anketa] = $this->reminderAnketa('claim-archived');
        self::assertTrue($anketaRepo->markArchivedIfOpen($anketa, new \DateTimeImmutable(), false));

        self::assertFalse($anketaRepo->claimReminder($anketa->getId(), new \DateTimeImmutable('2091-08-06T00:00:00Z'), new \DateTimeImmutable()));
    }

    /**
     * The claim records the day it was for: a move to another day makes a reminder due
     * again, a move back to the reminded day (or within it) doesn't.
     */
    public function testAReminderIsDueAgainOnlyForAnotherDay(): void
    {
        [$anketaRepo, $anketa] = $this->reminderAnketa('due-again');
        $monday = new \DateTimeImmutable('2091-08-06T00:00:00Z');
        $wednesday = new \DateTimeImmutable('2091-08-08T00:00:00Z');
        self::assertTrue($anketaRepo->claimReminder($anketa->getId(), $monday, new \DateTimeImmutable()));

        $anketa->reschedule($monday->setTime(10, 0));
        $this->entityManager()->flush();
        self::assertFalse($this->isDue($anketa, $monday));

        $anketa->reschedule($wednesday);
        $this->entityManager()->flush();
        self::assertTrue($this->isDue($anketa, $wednesday));

        $anketa->reschedule($monday);
        $this->entityManager()->flush();
        self::assertFalse($this->isDue($anketa, $monday), 'moved back to the day already reminded');
    }

    /** GitHub issue #202: the follow-up is claimed for its meeting day like the reminder. */
    public function testClaimFollowUpSucceedsOncePerMeetingDay(): void
    {
        [$anketaRepo, $anketa] = $this->reminderAnketa('follow-up-once');
        $monday = new \DateTimeImmutable('2091-08-06T00:00:00Z');

        self::assertTrue($this->isDueForFollowUp($anketa, $monday));
        self::assertTrue($anketaRepo->claimFollowUp($anketa->getId(), $monday));
        self::assertFalse($anketaRepo->claimFollowUp($anketa->getId(), $monday));
        self::assertFalse($this->isDueForFollowUp($anketa, $monday));
        // Its own column: the reminder for that day is still due.
        self::assertTrue($this->isDue($anketa, $monday));
    }

    public function testReleaseFollowUpRestoresThePreviousDay(): void
    {
        [$anketaRepo, $anketa] = $this->reminderAnketa('follow-up-release');
        $monday = new \DateTimeImmutable('2091-08-06T00:00:00Z');
        $wednesday = new \DateTimeImmutable('2091-08-08T00:00:00Z');
        self::assertTrue($anketaRepo->claimFollowUp($anketa->getId(), $monday));
        $anketa->reschedule($wednesday);
        $this->entityManager()->flush();
        self::assertTrue($this->isDueForFollowUp($anketa, $wednesday), 'moved to another day');
        self::assertTrue($anketaRepo->claimFollowUp($anketa->getId(), $wednesday));

        $anketaRepo->releaseFollowUp($anketa->getId(), $wednesday, $monday);

        self::assertTrue($this->isDueForFollowUp($anketa, $wednesday));
        $anketa->reschedule($monday);
        $this->entityManager()->flush();
        self::assertFalse($this->isDueForFollowUp($anketa, $monday), 'moved back to the day already followed up');
    }

    public function testClaimFollowUpMissesAMeetingMovedToAnotherDay(): void
    {
        [$anketaRepo, $anketa] = $this->reminderAnketa('follow-up-moved');
        $anketa->reschedule(new \DateTimeImmutable('2091-08-08T00:00:00Z'));
        $this->entityManager()->flush();

        self::assertFalse($anketaRepo->claimFollowUp($anketa->getId(), new \DateTimeImmutable('2091-08-06T00:00:00Z')));
    }

    public function testClaimFollowUpRefusesAnArchivedAnketa(): void
    {
        [$anketaRepo, $anketa] = $this->reminderAnketa('follow-up-archived');
        $monday = new \DateTimeImmutable('2091-08-06T00:00:00Z');
        self::assertTrue($anketaRepo->markArchivedIfOpen($anketa, new \DateTimeImmutable(), false));

        self::assertFalse($this->isDueForFollowUp($anketa, $monday));
        self::assertFalse($anketaRepo->claimFollowUp($anketa->getId(), $monday));
    }

    /** @return array{0: AnketaRepository, 1: Anketa} */
    private function reminderAnketa(string $label): array
    {
        [$empClient, , , $manager] = $this->makePair($label);
        $anketaId = $this->createAnketaAsEmployee($empClient, $manager['id'], ['meetingDate' => '2091-08-06T00:00:00Z'])['json']['id'];
        $anketaRepo = $this->entityManager()->getRepository(Anketa::class);
        $anketa = $anketaRepo->find($anketaId);
        self::assertNotNull($anketa);

        return [$anketaRepo, $anketa];
    }

    /** Checked for this one anketa: the shared test database can hold others meeting that day. */
    private function isDue(Anketa $anketa, \DateTimeImmutable $dayStart): bool
    {
        return \in_array($anketa->getId(), $this->entityManager()->getRepository(Anketa::class)->findDueForReminder($dayStart), true);
    }

    private function isDueForFollowUp(Anketa $anketa, \DateTimeImmutable $dayStart): bool
    {
        return \in_array($anketa->getId(), $this->entityManager()->getRepository(Anketa::class)->findDueForFollowUp($dayStart), true);
    }

    /**
     * GitHub issue #168: two participants ticking boxes at the same moment both send
     * expectedVersion 0. Only the first may win; the second must not overwrite it.
     */
    public function testSaveDiscussedIfVersionOnlySucceedsOnceForAVersion(): void
    {
        [$empClient, , , $manager] = $this->makePair('discussed-once');
        $anketaId = $this->createAnketaAsEmployee($empClient, $manager['id'])['json']['id'];

        $em = $this->entityManager();
        $anketaRepo = $em->getRepository(Anketa::class);
        $anketa = $anketaRepo->find($anketaId);
        self::assertNotNull($anketa);

        self::assertTrue($anketaRepo->saveDiscussedIfVersion($anketa, 'first', 0));
        self::assertFalse($anketaRepo->saveDiscussedIfVersion($anketa, 'second', 0));
        self::assertTrue($anketaRepo->saveDiscussedIfVersion($anketa, 'third', 1));

        $em->clear();
        $reloaded = $anketaRepo->find($anketaId);
        self::assertNotNull($reloaded);
        self::assertSame('third', $reloaded->getDiscussedBlob());
        self::assertSame(2, $reloaded->getDiscussedVersion());
    }

    /**
     * GitHub issue #206: the topics list is saved the same way, and on its own version:
     * a topics save neither needs nor moves the discussed ticks' version.
     */
    public function testSaveTopicsIfVersionOnlySucceedsOnceForAVersionAndLeavesDiscussedAlone(): void
    {
        [$empClient, , , $manager] = $this->makePair('topics-once');
        $anketaId = $this->createAnketaAsEmployee($empClient, $manager['id'])['json']['id'];

        $em = $this->entityManager();
        $anketaRepo = $em->getRepository(Anketa::class);
        $anketa = $anketaRepo->find($anketaId);
        self::assertNotNull($anketa);

        self::assertTrue($anketaRepo->saveDiscussedIfVersion($anketa, 'ticks', 0));
        self::assertTrue($anketaRepo->saveTopicsIfVersion($anketa, 'first', 0));
        self::assertFalse($anketaRepo->saveTopicsIfVersion($anketa, 'second', 0));
        self::assertTrue($anketaRepo->saveTopicsIfVersion($anketa, 'third', 1));

        $em->clear();
        $reloaded = $anketaRepo->find($anketaId);
        self::assertNotNull($reloaded);
        self::assertSame('third', $reloaded->getTopicsBlob());
        self::assertSame(2, $reloaded->getTopicsVersion());
        self::assertSame('ticks', $reloaded->getDiscussedBlob());
        self::assertSame(1, $reloaded->getDiscussedVersion());
    }

    public function testSaveTopicsIfVersionRefusesAnArchivedAnketa(): void
    {
        [$empClient, , , $manager] = $this->makePair('topics-archived');
        $anketaId = $this->createAnketaAsEmployee($empClient, $manager['id'])['json']['id'];

        $em = $this->entityManager();
        $anketaRepo = $em->getRepository(Anketa::class);
        $anketa = $anketaRepo->find($anketaId);
        self::assertNotNull($anketa);

        // The stale in-memory copy a request loaded just before the archive landed.
        self::assertTrue($anketaRepo->markArchivedIfOpen($anketa, new \DateTimeImmutable('2026-09-01 10:00:00'), false));
        self::assertFalse($anketaRepo->saveTopicsIfVersion($anketa, 'late', 0));

        $em->clear();
        $reloaded = $anketaRepo->find($anketaId);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getTopicsBlob());
        self::assertSame(0, $reloaded->getTopicsVersion());
    }

    public function testMarkArchivedIfOpenWithATopicsVersionOnlyMatchesThatVersion(): void
    {
        [$empClient, , , $manager] = $this->makePair('archive-topics-version');
        $anketaId = $this->createAnketaAsEmployee($empClient, $manager['id'])['json']['id'];

        $em = $this->entityManager();
        $anketaRepo = $em->getRepository(Anketa::class);
        $anketa = $anketaRepo->find($anketaId);
        self::assertNotNull($anketa);
        self::assertTrue($anketaRepo->saveTopicsIfVersion($anketa, 'topics', 0));

        $archivedAt = new \DateTimeImmutable('2026-09-01 10:00:00');
        self::assertFalse($anketaRepo->markArchivedIfOpen($anketa, $archivedAt, false, 0));
        $em->clear();
        $stillOpen = $anketaRepo->find($anketaId);
        self::assertNotNull($stillOpen);
        self::assertFalse($stillOpen->isArchived());

        self::assertTrue($anketaRepo->markArchivedIfOpen($stillOpen, $archivedAt, false, 1));
        $em->clear();
        self::assertTrue($anketaRepo->find($anketaId)?->isArchived());
    }

    public function testSaveTopicsIfVersionTouchesOnlyItsOwnAnketa(): void
    {
        [$empClient, , , $manager] = $this->makePair('topics-own-row');
        $anketaId = $this->createAnketaAsEmployee($empClient, $manager['id'])['json']['id'];
        // The same pair's second open meeting (a one-off).
        $otherId = $this->createAnketaAsEmployee($empClient, $manager['id'])['json']['id'];

        $em = $this->entityManager();
        $anketaRepo = $em->getRepository(Anketa::class);
        $anketa = $anketaRepo->find($anketaId);
        self::assertNotNull($anketa);

        self::assertTrue($anketaRepo->saveTopicsIfVersion($anketa, 'mine', 0));

        $em->clear();
        $other = $anketaRepo->find($otherId);
        self::assertNotNull($other);
        self::assertNull($other->getTopicsBlob());
        self::assertSame(0, $other->getTopicsVersion());
    }

    public function testSaveDiscussedIfVersionRefusesAnArchivedAnketa(): void
    {
        [$empClient, , , $manager] = $this->makePair('discussed-archived');
        $anketaId = $this->createAnketaAsEmployee($empClient, $manager['id'])['json']['id'];

        $em = $this->entityManager();
        $anketaRepo = $em->getRepository(Anketa::class);
        $anketa = $anketaRepo->find($anketaId);
        self::assertNotNull($anketa);

        // The stale in-memory copy a request loaded just before the archive landed.
        self::assertTrue($anketaRepo->markArchivedIfOpen($anketa, new \DateTimeImmutable('2026-09-01 10:00:00'), false));
        self::assertFalse($anketaRepo->saveDiscussedIfVersion($anketa, 'late', 0));

        $em->clear();
        $reloaded = $anketaRepo->find($anketaId);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getDiscussedBlob());
        self::assertSame(0, $reloaded->getDiscussedVersion());
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
