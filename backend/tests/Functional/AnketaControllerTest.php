<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

class AnketaControllerTest extends ApiTestCase
{
    public function testCreateRequiresPeriodicityForANewPair(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('no-periodicity');

        $result = $this->createAnketaAsEmployee($employeeClient, $manager['id'], ['periodicityDays' => null]);

        self::assertSame(400, $result['status']);
    }

    public function testCreateSucceedsForANewPair(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('create-ok');

        $result = $this->createAnketaAsEmployee($employeeClient, $manager['id']);

        self::assertSame(201, $result['status']);
        self::assertArrayHasKey('id', $result['json']);
    }

    public function testCreateRejectsAnInvalidRole(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('bad-role');

        $result = $this->createAnketaAsEmployee($employeeClient, $manager['id'], ['myRole' => 'boss']);

        self::assertSame(400, $result['status']);
    }

    public function testCreateRejectsAnUnknownCounterpart(): void
    {
        $employeeClient = static::createClient();
        $this->activateUser($employeeClient, $this->uniqueEmail('create-unknown-cp'));

        $result = $this->createAnketaAsEmployee($employeeClient, '00000000-0000-0000-0000-000000000000');

        self::assertSame(404, $result['status']);
    }

    /**
     * A blocked/deleted account is already excluded from the counterpart-picker
     * (ExcludeDeletedUsersExtension), but that only stops the normal UI flow — this
     * checks the server itself refuses a direct API call against a previously-known id,
     * same "treat like nonexistent" shape as an unknown or cross-company counterpart.
     */
    public function testCreateRejectsABlockedCounterpart(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('create-blocked-cp');

        $managerEntity = $this->entityManager()->find(User::class, $manager['id']);
        \assert($managerEntity instanceof User);
        $managerEntity->setBlocked(true);
        $this->entityManager()->flush();

        $result = $this->createAnketaAsEmployee($employeeClient, $manager['id']);

        self::assertSame(404, $result['status']);
    }

    public function testCreateRejectsADeletedCounterpart(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('create-deleted-cp');

        $managerEntity = $this->entityManager()->find(User::class, $manager['id']);
        \assert($managerEntity instanceof User);
        $managerEntity->delete();
        $this->entityManager()->flush();

        $result = $this->createAnketaAsEmployee($employeeClient, $manager['id']);

        self::assertSame(404, $result['status']);
    }

    public function testListShowsTheAnketaToBothParticipantsWithCorrectRoles(): void
    {
        [$employeeClient, $employee, $managerClient, $manager] = $this->makePair('list-both-sides');
        $created = $this->createAnketaAsEmployee($employeeClient, $manager['id']);

        $fromEmployee = $this->jsonRequest($employeeClient, 'GET', '/api/anketas');
        $fromManager = $this->jsonRequest($managerClient, 'GET', '/api/anketas');

        $employeeRow = self::findById($fromEmployee['json'], $created['json']['id']);
        $managerRow = self::findById($fromManager['json'], $created['json']['id']);

        self::assertSame('employee', $employeeRow['myRole']);
        self::assertSame($manager['email'], $employeeRow['counterpartEmail']);
        self::assertSame('manager', $managerRow['myRole']);
        self::assertSame($employee['email'], $managerRow['counterpartEmail']);
    }

    public function testGetRejectsANonParticipant(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('get-non-participant');
        $created = $this->createAnketaAsEmployee($employeeClient, $manager['id']);

        $stranger = $this->secondClient();
        $this->activateUser($stranger, $this->uniqueEmail('get-stranger'));

        $result = $this->jsonRequest($stranger, 'GET', "/api/anketas/{$created['json']['id']}");

        self::assertSame(403, $result['status']);
        self::assertSame('Not a participant.', $result['json']['error']);
    }

    public function testGetReturns404ForAnUnknownId(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('get-unknown'));

        $result = $this->jsonRequest($client, 'GET', '/api/anketas/00000000-0000-0000-0000-000000000000');

        self::assertSame(404, $result['status']);
    }

    public function testBulkReturnsEveryAnketaForBothParticipantsWithCorrectRoles(): void
    {
        [$employeeClient, $employee, $managerClient, $manager] = $this->makePair('bulk-both-sides');
        $first = $this->createAnketaAsEmployee($employeeClient, $manager['id']);
        $second = $this->createAnketaAsEmployee($employeeClient, $manager['id'], [
            'meetingDate' => (new \DateTimeImmutable('+2 days'))->format(\DateTimeImmutable::ATOM),
        ]);

        $fromEmployee = $this->jsonRequest($employeeClient, 'GET', '/api/anketas/bulk');
        $fromManager = $this->jsonRequest($managerClient, 'GET', '/api/anketas/bulk');

        self::assertSame(200, $fromEmployee['status']);
        self::assertCount(2, $fromEmployee['json']);
        self::assertCount(2, $fromManager['json']);

        $employeeRow = self::findById($fromEmployee['json'], $first['json']['id']);
        $managerRow = self::findById($fromManager['json'], $first['json']['id']);
        self::assertSame('employee', $employeeRow['myRole']);
        self::assertSame($manager['email'], $employeeRow['counterpartEmail']);
        self::assertSame('manager', $managerRow['myRole']);
        self::assertSame($employee['email'], $managerRow['counterpartEmail']);

        // Full detail fields (not just summary ones) are present, same shape as get().
        self::assertArrayHasKey('mySealedKey', $employeeRow);
        self::assertArrayHasKey('employeeBlob', $employeeRow);
        self::assertArrayHasKey('goals', $employeeRow);

        // findById() itself fails the test if the second anketa is missing from the response.
        self::findById($fromEmployee['json'], $second['json']['id']);
    }

    public function testBulkNeverReturnsAnotherUsersAnketas(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('bulk-isolation');
        $this->createAnketaAsEmployee($employeeClient, $manager['id']);

        $stranger = $this->secondClient();
        $this->activateUser($stranger, $this->uniqueEmail('bulk-stranger'));

        $result = $this->jsonRequest($stranger, 'GET', '/api/anketas/bulk');

        self::assertSame(200, $result['status']);
        self::assertSame([], $result['json']);
    }

    public function testBulkAttachesGoalsToTheCorrectAnketaWhenTheRequesterHasSeveral(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('bulk-goals');
        $first = $this->createAnketaAsEmployee($employeeClient, $manager['id']);
        $second = $this->createAnketaAsEmployee($employeeClient, $manager['id'], [
            'meetingDate' => (new \DateTimeImmutable('+2 days'))->format(\DateTimeImmutable::ATOM),
        ]);

        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$first['json']['id']}/goals", [
            'goalUuid' => 'bulk-goal-first',
            'title' => 'Goal on the first anketa',
        ]);
        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$second['json']['id']}/goals", [
            'goalUuid' => 'bulk-goal-second-a',
            'title' => 'Goal A on the second anketa',
        ]);
        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$second['json']['id']}/goals", [
            'goalUuid' => 'bulk-goal-second-b',
            'title' => 'Goal B on the second anketa',
        ]);

        $result = $this->jsonRequest($employeeClient, 'GET', '/api/anketas/bulk');

        $firstRow = self::findById($result['json'], $first['json']['id']);
        $secondRow = self::findById($result['json'], $second['json']['id']);

        self::assertCount(1, $firstRow['goals']);
        self::assertSame('bulk-goal-first', $firstRow['goals'][0]['goalUuid']);

        self::assertCount(2, $secondRow['goals']);
        $secondGoalUuids = array_column($secondRow['goals'], 'goalUuid');
        self::assertContains('bulk-goal-second-a', $secondGoalUuids);
        self::assertContains('bulk-goal-second-b', $secondGoalUuids);
    }

    public function testSaveCommentsSucceedsAndIncrementsVersion(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('comments-ok');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $result = $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/comments", [
            'blob' => 'comment-blob-v1',
            'expectedVersion' => 0,
        ]);

        self::assertSame(200, $result['status']);
        self::assertSame(1, $result['json']['commentsVersion']);
    }

    public function testSaveCommentsConflictReturns409WithCurrentState(): void
    {
        [$employeeClient, , $managerClient, $manager] = $this->makePair('comments-conflict');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        // Manager writes first, moving the version to 1.
        $this->jsonRequest($managerClient, 'PUT', "/api/anketas/{$anketaId}/comments", [
            'blob' => 'manager-blob',
            'expectedVersion' => 0,
        ]);

        // Employee still thinks the version is 0.
        $result = $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/comments", [
            'blob' => 'employee-blob',
            'expectedVersion' => 0,
        ]);

        self::assertSame(409, $result['status']);
        self::assertSame('manager-blob', $result['json']['commentsBlob']);
        self::assertSame(1, $result['json']['commentsVersion']);
    }

    public function testSaveOutcomesConflictReturns409(): void
    {
        [$employeeClient, , $managerClient, $manager] = $this->makePair('outcomes-conflict');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $this->jsonRequest($managerClient, 'PUT', "/api/anketas/{$anketaId}/outcomes", [
            'blob' => 'manager-outcomes',
            'expectedVersion' => 0,
        ]);

        $result = $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/outcomes", [
            'blob' => 'employee-outcomes',
            'expectedVersion' => 0,
        ]);

        self::assertSame(409, $result['status']);
    }

    public function testCreateGoalAndAuthorCanUpdateIt(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('goal-author');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $created = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/goals", [
            'goalUuid' => 'goal-uuid-1',
            'title' => 'Ship the thing',
        ]);
        self::assertSame(201, $created['status']);
        self::assertSame('in_progress', $created['json']['status']);

        $updated = $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/goals/{$created['json']['id']}", [
            'status' => 'achieved',
        ]);

        self::assertSame(200, $updated['status']);
        self::assertSame('achieved', $updated['json']['status']);
    }

    public function testUpdateGoalRejectsANonAuthor(): void
    {
        [$employeeClient, , $managerClient, $manager] = $this->makePair('goal-non-author');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $created = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/goals", [
            'goalUuid' => 'goal-uuid-2',
            'title' => 'Employee-authored goal',
        ]);

        $result = $this->jsonRequest($managerClient, 'PUT', "/api/anketas/{$anketaId}/goals/{$created['json']['id']}", [
            'status' => 'cancelled',
        ]);

        self::assertSame(403, $result['status']);
        self::assertSame("Only the goal's author can edit it.", $result['json']['error']);
    }

    public function testPublishMarksThatSideAsPublished(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('publish-ok');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $result = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/publish", ['blob' => 'published-blob']);

        self::assertSame(200, $result['status']);

        $get = $this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$anketaId}");
        self::assertNotNull($get['json']['employeePublishedAt']);
    }

    public function testPublishingTwiceReturns409(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('publish-twice');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/publish", ['blob' => 'v1']);
        $result = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/publish", ['blob' => 'v2']);

        self::assertSame(409, $result['status']);
        self::assertSame('Already published.', $result['json']['error']);
    }

    public function testArchiveWithoutAutoRecreationCreatesNoNextAnketa(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('archive-no-next');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $before = \count($this->jsonRequest($employeeClient, 'GET', '/api/anketas')['json']);

        $result = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/archive", [
            'missed' => false,
            'skipNextMeeting' => true,
        ]);

        self::assertSame(200, $result['status']);
        $after = \count($this->jsonRequest($employeeClient, 'GET', '/api/anketas')['json']);
        self::assertSame($before, $after);
    }

    public function testArchiveWithAutoRecreationCreatesTheNextAnketa(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('archive-with-next');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $before = \count($this->jsonRequest($employeeClient, 'GET', '/api/anketas')['json']);

        $result = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/archive", [
            'missed' => false,
            'skipNextMeeting' => false,
            'mySealedKey' => str_repeat('n', 44),
            'counterpartSealedKey' => str_repeat('o', 44),
        ]);

        self::assertSame(200, $result['status']);
        $after = \count($this->jsonRequest($employeeClient, 'GET', '/api/anketas')['json']);
        self::assertSame($before + 1, $after);
    }

    public function testArchiveSkipsAutoRecreationWhenTheCounterpartIsBlocked(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('archive-blocked-cp');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $managerEntity = $this->entityManager()->find(User::class, $manager['id']);
        \assert($managerEntity instanceof User);
        $managerEntity->setBlocked(true);
        $this->entityManager()->flush();

        $before = \count($this->jsonRequest($employeeClient, 'GET', '/api/anketas')['json']);

        $result = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/archive", [
            'missed' => false,
            'skipNextMeeting' => false, // client asks for auto-recreation...
            'mySealedKey' => str_repeat('n', 44),
            'counterpartSealedKey' => str_repeat('o', 44),
        ]);

        self::assertSame(200, $result['status']);
        // ...but the server forces the skip anyway, since the counterpart is blocked.
        $after = \count($this->jsonRequest($employeeClient, 'GET', '/api/anketas')['json']);
        self::assertSame($before, $after);
    }

    public function testRescheduleRejectsOnceArchived(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('reschedule-archived');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];
        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/archive", ['missed' => false, 'skipNextMeeting' => true]);

        $result = $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/meeting-date", [
            'meetingDate' => (new \DateTimeImmutable('+2 days'))->format(\DateTimeImmutable::ATOM),
        ]);

        self::assertSame(409, $result['status']);
    }

    public function testSaveGoalCheckpointsRejectsOnceArchived(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('checkpoints-archived');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];
        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/archive", ['missed' => false, 'skipNextMeeting' => true]);

        $result = $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/goal-checkpoints", [
            'blob' => 'checkpoints',
            'expectedVersion' => 0,
        ]);

        self::assertSame(409, $result['status']);
        self::assertSame('Anketa is archived.', $result['json']['error']);
    }

    public function testPeriodicityIsInheritedForAContinuingPair(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('periodicity-inherit');
        $firstId = $this->createAnketaAsEmployee($employeeClient, $manager['id'], ['periodicityDays' => 14])['json']['id'];
        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$firstId}/archive", ['missed' => false, 'skipNextMeeting' => true]);

        // No periodicityDays this time — must be inherited from the pair's archived anketa.
        $second = $this->createAnketaAsEmployee($employeeClient, $manager['id'], ['periodicityDays' => null]);

        self::assertSame(201, $second['status']);
        $get = $this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$second['json']['id']}");
        self::assertSame(14, $get['json']['periodicityDays']);
    }

    public function testGoalCarriesForwardToTheNextAnketaForAContinuingPair(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('goal-carry-forward');
        $firstId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $goal = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$firstId}/goals", [
            'goalUuid' => 'carried-goal-uuid',
            'title' => 'Still working on it',
        ]);
        self::assertSame(201, $goal['status']);

        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$firstId}/archive", ['missed' => false, 'skipNextMeeting' => true]);

        $second = $this->createAnketaAsEmployee($employeeClient, $manager['id'], ['periodicityDays' => null]);
        $get = $this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$second['json']['id']}");

        $goalUuids = array_column($get['json']['goals'], 'goalUuid');
        self::assertContains('carried-goal-uuid', $goalUuids);
        $carried = self::findByGoalUuid($get['json']['goals'], 'carried-goal-uuid');
        self::assertNotSame($goal['json']['id'], $carried['id'], 'the carried-forward row must be a fresh id, not the original');
        self::assertSame('in_progress', $carried['status']);
    }

    public function testListShowsNoCounterpartKeyOutdatedByDefault(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('key-outdated-default');
        $created = $this->createAnketaAsEmployee($employeeClient, $manager['id']);

        $fromEmployee = $this->jsonRequest($employeeClient, 'GET', '/api/anketas');
        $row = self::findById($fromEmployee['json'], $created['json']['id']);

        self::assertFalse($row['counterpartKeyOutdated']);
    }

    public function testListShowsCounterpartKeyOutdatedAfterAPasswordResetAndClearsAfterReshare(): void
    {
        [$employeeClient, $employee, $managerClient, $manager] = $this->makePair('key-outdated-cycle');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        // Backdate the anketa's sealedKeyUpdatedAt columns by an hour — the stored
        // datetime_immutable columns are only second-precision (same known limitation
        // as Goal::createdAt, see docs/history.md's Phase 6f notes), so without a real gap the
        // reset below and the anketa's creation could tie within the same second and the
        // ">" staleness comparison would never trip.
        $past = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE anketas SET employeeSealedKeyUpdatedAt = ?, managerSealedKeyUpdatedAt = ? WHERE id = ?',
            [$past, $past, $anketaId],
        );
        $this->entityManager()->clear();

        // The manager resets their credentials — their public key changes.
        $managerEntity = $this->entityManager()->find(User::class, $manager['id']);
        \assert($managerEntity instanceof User);
        $managerEntity->resetCredentials(str_repeat('x', 44), str_repeat('y', 44), str_repeat('z', 44));
        $this->entityManager()->flush();

        // From the employee's side, the manager (their counterpart) now looks outdated.
        $afterReset = $this->jsonRequest($employeeClient, 'GET', '/api/anketas');
        self::assertTrue(self::findById($afterReset['json'], $anketaId)['counterpartKeyOutdated']);

        // From the manager's own side, nothing looks outdated — it's their own key that changed, not their counterpart's.
        $managerView = $this->jsonRequest($managerClient, 'GET', '/api/anketas');
        self::assertFalse(self::findById($managerView['json'], $anketaId)['counterpartKeyOutdated']);

        // The employee re-shares the anketa key, resealed to the manager's new public key.
        $reshare = $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/reshare-key", [
            'sealedKey' => str_repeat('r', 44),
        ]);
        self::assertSame(200, $reshare['status']);

        $afterReshare = $this->jsonRequest($employeeClient, 'GET', '/api/anketas');
        self::assertFalse(self::findById($afterReshare['json'], $anketaId)['counterpartKeyOutdated']);
    }

    public function testReshareKeyRejectsANonParticipant(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('reshare-non-participant');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $stranger = $this->secondClient();
        $this->activateUser($stranger, $this->uniqueEmail('reshare-stranger'));

        $result = $this->jsonRequest($stranger, 'PUT', "/api/anketas/{$anketaId}/reshare-key", [
            'sealedKey' => str_repeat('r', 44),
        ]);

        self::assertSame(403, $result['status']);
    }

    public function testReshareKeyRejectsAMissingSealedKey(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('reshare-missing-key');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $result = $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/reshare-key", []);

        self::assertSame(400, $result['status']);
    }

    public function testReshareKeyOnlyTouchesTheCounterpartsSide(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('reshare-only-counterpart');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        // The employee reshares — that updates the *manager's* (counterpart's) sealed key.
        $result = $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/reshare-key", [
            'sealedKey' => str_repeat('r', 44),
        ]);
        self::assertSame(200, $result['status']);

        $anketa = $this->entityManager()->find(\App\Entity\Anketa::class, $anketaId);
        \assert($anketa instanceof \App\Entity\Anketa);
        $managerEntity = $this->entityManager()->find(User::class, $manager['id']);
        \assert($managerEntity instanceof User);

        self::assertSame(str_repeat('r', 44), $anketa->sealedKeyFor($managerEntity));
        // The employee's own side is untouched.
        self::assertSame(str_repeat('e', 44), $anketa->sealedKeyFor($anketa->getEmployee()));
    }

    public function testDeletingAUserClearsTheirUnpublishedDraftButLeavesAPublishedSideUntouched(): void
    {
        [$employeeClient, , $managerClient, $manager] = $this->makePair('delete-consequence');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        // Employee saves a private draft (never published); manager publishes theirs.
        $anketa = $this->entityManager()->find(\App\Entity\Anketa::class, $anketaId);
        \assert($anketa instanceof \App\Entity\Anketa);
        $anketa->saveDraft($anketa->getEmployee(), 'employee-draft-blob');
        $anketa->publish($anketa->getManager(), 'manager-published-blob');
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        // The employee deletes their own account for real, through the real endpoint.
        $result = $this->jsonRequest($employeeClient, 'DELETE', '/api/me', ['currentAuthKey' => str_repeat('a', 44)]);
        self::assertSame(200, $result['status']);

        $afterDeletion = $this->entityManager()->find(\App\Entity\Anketa::class, $anketaId);
        \assert($afterDeletion instanceof \App\Entity\Anketa);
        self::assertNull($afterDeletion->getEmployeeBlob(), 'the unpublished draft must be cleared');
        self::assertSame('manager-published-blob', $afterDeletion->getManagerBlob(), 'a published side is shared history — no cascade');

        // The manager (counterpart) now sees this anketa's counterpart as deleted.
        $listAsManager = $this->jsonRequest($managerClient, 'GET', '/api/anketas');
        self::assertTrue(self::findById($listAsManager['json'], $anketaId)['counterpartDeleted']);
    }

    public function testCounterpartDeletedIsFalseByDefault(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('counterpart-deleted-default');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $list = $this->jsonRequest($employeeClient, 'GET', '/api/anketas');

        self::assertFalse(self::findById($list['json'], $anketaId)['counterpartDeleted']);
    }

    /**
     * @return array{0: KernelBrowser, 1: array{id: string, email: string, isAdmin: bool},
     *     2: KernelBrowser, 3: array{id: string, email: string, isAdmin: bool}}
     */
    private function makePair(string $label): array
    {
        $employeeClient = static::createClient();
        $employee = $this->activateUser($employeeClient, $this->uniqueEmail("anketa-{$label}-emp"));
        $managerClient = $this->secondClient();
        $manager = $this->activateUser($managerClient, $this->uniqueEmail("anketa-{$label}-mgr"));

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

        // Overrides that are explicitly null (e.g. "no periodicityDays this time")
        // should be omitted from the request body entirely, not sent as JSON null.
        $body = array_filter($body, static fn ($value) => null !== $value);

        return $this->jsonRequest($employeeClient, 'POST', '/api/anketas', $body);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private static function findById(array $rows, string $id): array
    {
        foreach ($rows as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        self::fail("no row with id {$id} found");
    }

    /**
     * @param array<int, array<string, mixed>> $goals
     *
     * @return array<string, mixed>
     */
    private static function findByGoalUuid(array $goals, string $goalUuid): array
    {
        foreach ($goals as $goal) {
            if ($goal['goalUuid'] === $goalUuid) {
                return $goal;
            }
        }
        self::fail("no goal with goalUuid {$goalUuid} found");
    }
}
