<?php

namespace App\Tests\Functional;

use App\Entity\Anketa;
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

    public function testCreateAndGetReturnTheCurrentFormVersion(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('form-version');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $list = $this->jsonRequest($employeeClient, 'GET', '/api/anketas');
        $listRow = self::findById($list['json'], $anketaId);
        self::assertSame(Anketa::CURRENT_FORM_VERSION, $listRow['formVersion']);

        $get = $this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$anketaId}");
        self::assertSame(Anketa::CURRENT_FORM_VERSION, $get['json']['formVersion']);
    }

    public function testAutoRecreatedAnketaGetsTheCurrentFormVersionRegardlessOfThePreviousOnes(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('form-version-carry');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/archive", [
            'missed' => false,
            'skipNextMeeting' => false,
            'mySealedKey' => str_repeat('n', 44),
            'counterpartSealedKey' => str_repeat('o', 44),
        ]);

        $list = $this->jsonRequest($employeeClient, 'GET', '/api/anketas')['json'];
        $next = array_values(array_filter($list, static fn (array $row) => $row['id'] !== $anketaId));
        self::assertCount(1, $next);
        self::assertSame(Anketa::CURRENT_FORM_VERSION, $next[0]['formVersion']);
    }

    public function testCreateRejectsAnInvalidRole(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('bad-role');

        $result = $this->createAnketaAsEmployee($employeeClient, $manager['id'], ['myRole' => 'boss']);

        self::assertSame(400, $result['status']);
    }

    public function testCreateAndGetReturnTheDefaultTemplateKeyWhenOmitted(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('template-key-default');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $list = $this->jsonRequest($employeeClient, 'GET', '/api/anketas');
        $listRow = self::findById($list['json'], $anketaId);
        self::assertSame('regular', $listRow['templateKey']);

        $get = $this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$anketaId}");
        self::assertSame('regular', $get['json']['templateKey']);
    }

    public function testCreateRejectsAnInvalidTemplateKey(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('bad-template-key');

        $result = $this->createAnketaAsEmployee($employeeClient, $manager['id'], ['templateKey' => 'made-up-template']);

        self::assertSame(400, $result['status']);
    }

    public function testCreateAndGetAcceptTheOnboardingTemplateKey(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('template-key-onboarding');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'], ['templateKey' => 'onboarding'])['json']['id'];

        $list = $this->jsonRequest($employeeClient, 'GET', '/api/anketas');
        $listRow = self::findById($list['json'], $anketaId);
        self::assertSame('onboarding', $listRow['templateKey']);

        $get = $this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$anketaId}");
        self::assertSame('onboarding', $get['json']['templateKey']);
    }

    public function testCreateAndGetAcceptTheCareerGrowthTemplateKey(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('template-key-career-growth');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'], ['templateKey' => 'career_growth'])['json']['id'];

        $list = $this->jsonRequest($employeeClient, 'GET', '/api/anketas');
        $listRow = self::findById($list['json'], $anketaId);
        self::assertSame('career_growth', $listRow['templateKey']);

        $get = $this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$anketaId}");
        self::assertSame('career_growth', $get['json']['templateKey']);
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

    public function testListIncludesTheCounterpartsDisplayNameWhenSet(): void
    {
        $employeeClient = static::createClient();
        $employee = $this->activateUser($employeeClient, $this->uniqueEmail('list-name-emp'));
        $managerClient = $this->secondClient();
        $manager = $this->activateUser($managerClient, $this->uniqueEmail('list-name-mgr'), displayName: 'Jordan Blake');

        $created = $this->createAnketaAsEmployee($employeeClient, $manager['id']);

        $fromEmployee = $this->jsonRequest($employeeClient, 'GET', '/api/anketas');
        $employeeRow = self::findById($fromEmployee['json'], $created['json']['id']);

        self::assertSame('Jordan Blake', $employeeRow['counterpartName']);

        // The manager never set one — falls back to '', not the employee's own id/email.
        $fromManager = $this->jsonRequest($managerClient, 'GET', '/api/anketas');
        $managerRow = self::findById($fromManager['json'], $created['json']['id']);
        self::assertSame('', $managerRow['counterpartName']);
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

    public function testLiveStateReturnsCurrentScalarsAndVersions(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('live-state-ok');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/comments", [
            'blob' => 'comment-blob-v1',
            'expectedVersion' => 0,
        ]);
        $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/outcomes", [
            'blob' => 'outcomes-v1',
            'expectedVersion' => 0,
        ]);

        $result = $this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$anketaId}/live-state");

        self::assertSame(200, $result['status']);
        self::assertSame('employee', $result['json']['myRole']);
        self::assertSame(1, $result['json']['commentsVersion']);
        self::assertSame(1, $result['json']['outcomesVersion']);
        self::assertSame(0, $result['json']['goalCheckpointsVersion']);
        self::assertSame(0, $result['json']['employeeBlobVersion']);
        self::assertSame(0, $result['json']['managerBlobVersion']);
        self::assertNull($result['json']['myPublishedAt']);
        self::assertNull($result['json']['counterpartPublishedAt']);
        self::assertNull($result['json']['archivedAt']);
        self::assertArrayNotHasKey('commentsBlob', $result['json']);
        self::assertArrayNotHasKey('employeeBlob', $result['json']);
        // templateKey is immutable once an anketa is created, so it has nothing to poll
        // for — AnketaPresenter::serializeLiveState() explicitly excludes it, unlike
        // every other summarize() field, which this endpoint otherwise reuses wholesale.
        self::assertArrayNotHasKey('templateKey', $result['json']);
        self::assertArrayNotHasKey('oneOff', $result['json'], 'equally immutable (GitHub issue #111)');
    }

    public function testLiveStateReflectsCounterpartsRoleAndPublishState(): void
    {
        [$employeeClient, , $managerClient, $manager] = $this->makePair('live-state-roles');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/publish", [
            'blob' => 'employee-answers',
        ]);

        $fromManager = $this->jsonRequest($managerClient, 'GET', "/api/anketas/{$anketaId}/live-state");

        self::assertSame(200, $fromManager['status']);
        self::assertSame('manager', $fromManager['json']['myRole']);
        self::assertNull($fromManager['json']['myPublishedAt']);
        self::assertNotNull($fromManager['json']['counterpartPublishedAt']);
    }

    public function testLiveStateRejectsANonParticipant(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('live-state-non-participant');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $stranger = $this->secondClient();
        $this->activateUser($stranger, $this->uniqueEmail('live-state-stranger'));

        $result = $this->jsonRequest($stranger, 'GET', "/api/anketas/{$anketaId}/live-state");

        self::assertSame(403, $result['status']);
    }

    public function testLiveStateReturns404ForAnUnknownId(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('live-state-unknown'));

        $result = $this->jsonRequest($client, 'GET', '/api/anketas/00000000-0000-0000-0000-000000000000/live-state');

        self::assertSame(404, $result['status']);
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

    public function testSaveDraftRejectsOnceArchivedEvenIfNeverPublished(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('draft-archived');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];
        // Never published — archived directly from the draft state (e.g. via
        // "cancel as missed"), same as a real "missed meeting, no answers
        // ever filled in" anketa.
        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/archive", [
            'missed' => true,
            'skipNextMeeting' => true,
        ]);

        $result = $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/draft", [
            'blob' => 'draft-after-archive',
        ]);

        self::assertSame(409, $result['status']);
        self::assertSame('Anketa is archived.', $result['json']['error']);
    }

    public function testPublishRejectsOnceArchivedEvenIfNeverPublished(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('publish-archived');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];
        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/archive", [
            'missed' => true,
            'skipNextMeeting' => true,
        ]);

        $result = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/publish", [
            'blob' => 'publish-after-archive',
        ]);

        self::assertSame(409, $result['status']);
        self::assertSame('Anketa is archived.', $result['json']['error']);
    }

    public function testUpdateAnswersSucceedsAndIncrementsVersionWithoutTouchingPublishedAt(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('answers-ok');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];
        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/publish", ['blob' => 'v1']);
        $publishedAt = $this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$anketaId}")['json']['employeePublishedAt'];

        $result = $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/answers", [
            'blob' => 'v2',
            'expectedVersion' => 0,
        ]);

        self::assertSame(200, $result['status']);
        self::assertSame(1, $result['json']['blobVersion']);

        $get = $this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$anketaId}")['json'];
        self::assertSame('v2', $get['employeeBlob']);
        self::assertSame(1, $get['employeeBlobVersion']);
        // An edit must never look like a fresh publish — see
        // docs/decisions/2026-09-07-editable-published-anketa-answers.md.
        self::assertSame($publishedAt, $get['employeePublishedAt']);
    }

    public function testUpdateAnswersOnlyTouchesTheCallersSide(): void
    {
        [$employeeClient, , $managerClient, $manager] = $this->makePair('answers-caller-side');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];
        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/publish", ['blob' => 'employee-v1']);
        $this->jsonRequest($managerClient, 'POST', "/api/anketas/{$anketaId}/publish", ['blob' => 'manager-v1']);

        $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/answers", [
            'blob' => 'employee-v2',
            'expectedVersion' => 0,
        ]);

        $get = $this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$anketaId}")['json'];
        self::assertSame('employee-v2', $get['employeeBlob']);
        self::assertSame(1, $get['employeeBlobVersion']);
        // The manager's own (also published) side is untouched — only the caller's.
        self::assertSame('manager-v1', $get['managerBlob']);
        self::assertSame(0, $get['managerBlobVersion']);
    }

    public function testUpdateAnswersConflictReturns409WithCurrentState(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('answers-conflict');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];
        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/publish", ['blob' => 'v1']);
        // Simulates a second tab already having saved an edit, moving the version to 1.
        $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/answers", [
            'blob' => 'v2-from-other-tab',
            'expectedVersion' => 0,
        ]);

        // This tab still thinks the version is 0.
        $result = $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/answers", [
            'blob' => 'v2-from-this-tab',
            'expectedVersion' => 0,
        ]);

        self::assertSame(409, $result['status']);
        self::assertSame('v2-from-other-tab', $result['json']['blob']);
        self::assertSame(1, $result['json']['blobVersion']);
    }

    public function testUpdateAnswersRejectsBeforeFirstPublish(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('answers-not-published');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $result = $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/answers", [
            'blob' => 'v1',
            'expectedVersion' => 0,
        ]);

        self::assertSame(409, $result['status']);
        self::assertSame('Not published yet.', $result['json']['error']);
    }

    public function testUpdateAnswersRejectsOnceArchived(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('answers-archived');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];
        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/publish", ['blob' => 'v1']);
        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/archive", ['missed' => false, 'skipNextMeeting' => true]);

        $result = $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/answers", [
            'blob' => 'v2',
            'expectedVersion' => 0,
        ]);

        self::assertSame(409, $result['status']);
        self::assertSame('Anketa is archived.', $result['json']['error']);
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

    /**
     * An explicit nextMeetingDate must actually be used for the new anketa, not silently
     * ignored in favor of periodicityDays' own "archivedAt + periodicityDays" default —
     * the two are deliberately made to land on very different days here so a bug that
     * dropped the explicit date would produce a visibly wrong result, not a coincidental match.
     */
    public function testArchiveWithAutoRecreationUsesTheExplicitNextMeetingDate(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('archive-next-explicit-date');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'], ['periodicityDays' => 30])['json']['id'];

        $beforeIds = array_column($this->jsonRequest($employeeClient, 'GET', '/api/anketas')['json'], 'id');

        $explicitNextMeetingDate = new \DateTimeImmutable('+200 days');
        $result = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/archive", [
            'missed' => false,
            'skipNextMeeting' => false,
            'nextMeetingDate' => $explicitNextMeetingDate->format(\DateTimeImmutable::ATOM),
            'mySealedKey' => str_repeat('n', 44),
            'counterpartSealedKey' => str_repeat('o', 44),
        ]);
        self::assertSame(200, $result['status']);

        $afterList = $this->jsonRequest($employeeClient, 'GET', '/api/anketas')['json'];
        $newIds = array_values(array_diff(array_column($afterList, 'id'), $beforeIds));
        self::assertCount(1, $newIds);

        $nextAnketa = self::findById($afterList, $newIds[0]);
        $nextMeetingDate = new \DateTimeImmutable($nextAnketa['meetingDate']);
        self::assertSame($explicitNextMeetingDate->format('Y-m-d'), $nextMeetingDate->format('Y-m-d'));
    }

    /** Omitting "missed" must default to false, the same as the old manual-parsing code's `?? false`. */
    public function testArchiveDefaultsMissedToFalseWhenOmitted(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('archive-missed-default');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $result = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/archive", [
            'skipNextMeeting' => true,
        ]);
        self::assertSame(200, $result['status']);

        $list = $this->jsonRequest($employeeClient, 'GET', '/api/anketas')['json'];
        self::assertFalse(self::findById($list, $anketaId)['missed']);
    }

    /** The counterpart to the default-false tests below: an explicit `true` must actually take effect. */
    public function testArchiveSetsMissedToTrueWhenExplicitlyTrue(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('archive-missed-true');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $result = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/archive", [
            'missed' => true,
            'skipNextMeeting' => true,
        ]);
        self::assertSame(200, $result['status']);

        $list = $this->jsonRequest($employeeClient, 'GET', '/api/anketas')['json'];
        self::assertTrue(self::findById($list, $anketaId)['missed']);
    }

    /**
     * An explicit JSON `null` (not just an omitted key) must also default to false —
     * ArchiveAnketaRequest::$missed is nullable specifically so this doesn't 400, and
     * AnketaController::archive()'s own `$payload->missed ?? false` is what actually
     * applies the default once the DTO lets a null value through.
     */
    public function testArchiveDefaultsMissedToFalseWhenExplicitlyNull(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('archive-missed-null');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $result = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/archive", [
            'missed' => null,
            'skipNextMeeting' => true,
        ]);
        self::assertSame(200, $result['status']);

        $list = $this->jsonRequest($employeeClient, 'GET', '/api/anketas')['json'];
        self::assertFalse(self::findById($list, $anketaId)['missed']);
    }

    /** Omitting "skipNextMeeting" must default to false (auto-recreation happens), same as "missed" above. */
    public function testArchiveDefaultsSkipNextMeetingToFalseWhenOmitted(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('archive-skip-default');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $before = \count($this->jsonRequest($employeeClient, 'GET', '/api/anketas')['json']);

        $result = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/archive", [
            'missed' => false,
            'mySealedKey' => str_repeat('n', 44),
            'counterpartSealedKey' => str_repeat('o', 44),
        ]);
        self::assertSame(200, $result['status']);

        $after = \count($this->jsonRequest($employeeClient, 'GET', '/api/anketas')['json']);
        self::assertSame($before + 1, $after);
    }

    /** Same as testArchiveDefaultsMissedToFalseWhenExplicitlyNull() above, for skipNextMeeting. */
    public function testArchiveDefaultsSkipNextMeetingToFalseWhenExplicitlyNull(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('archive-skip-null');
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $before = \count($this->jsonRequest($employeeClient, 'GET', '/api/anketas')['json']);

        $result = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/archive", [
            'missed' => false,
            'skipNextMeeting' => null,
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

    /**
     * GitHub issue #111, the full scenario: archive auto-creates the next anketa, then a
     * second one is created by hand next to it. The hand-created one is a one-off: no
     * carry-forward, and archiving it with the form's defaults creates no successor.
     */
    public function testAHandCreatedAnketaNextToAnOpenOneIsAOneOff(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('one-off');
        $firstId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];
        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$firstId}/goals", [
            'goalUuid' => 'one-off-goal-uuid',
            'title' => 'Still working on it',
        ]);
        self::assertFalse($this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$firstId}")['json']['oneOff']);

        $regularId = $this->archiveWithNextAndReturnTheNewId($employeeClient, $firstId);
        $regular = $this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$regularId}")['json'];
        self::assertFalse($regular['oneOff'], 'an auto-created successor is never a one-off');
        self::assertContains('one-off-goal-uuid', array_column($regular['goals'], 'goalUuid'));

        $second = $this->createAnketaAsEmployee($employeeClient, $manager['id'], [
            'templateKey' => 'career_growth',
            'outcomesBlob' => 'client-carried-outcomes',
            'periodicityDays' => 7,
        ]);
        self::assertSame(201, $second['status']);
        $secondId = $second['json']['id'];

        $secondDetail = $this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$secondId}")['json'];
        self::assertTrue($secondDetail['oneOff']);
        self::assertSame([], $secondDetail['goals'], 'the already-open anketa got the carry-forward; this one must not duplicate it');
        self::assertNull($secondDetail['outcomesBlob'], 'a client-carried outcomesBlob must be dropped for the same reason');
        self::assertSame(30, $secondDetail['periodicityDays'], 'periodicity is inherited, a sent one ignored');

        // Archived with the form's defaults (skipNextMeeting unchecked, keys sent) — the
        // server still refuses to fork the chain.
        $archiveSecond = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$secondId}/archive", [
            'missed' => false,
            'skipNextMeeting' => false,
            'mySealedKey' => str_repeat('n', 44),
            'counterpartSealedKey' => str_repeat('o', 44),
        ]);
        self::assertSame(200, $archiveSecond['status']);
        self::assertSame([$regularId], $this->openAnketaIds($employeeClient));
    }

    /**
     * The review finding that moved this from a dynamic "does the pair have another open
     * anketa" check to the persisted flag: archiving the regular anketa *first*, while a
     * one-off is still open, must still continue the chain with the regular anketa's
     * carried goals — not hand the chain to the empty one-off.
     */
    public function testArchivingTheRegularAnketaWhileAOneOffIsOpenStillContinuesTheChain(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('one-off-regular-first');
        $regularId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];
        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$regularId}/goals", [
            'goalUuid' => 'regular-first-goal-uuid',
            'title' => 'Still working on it',
        ]);
        $oneOffId = $this->createAnketaAsEmployee($employeeClient, $manager['id'], ['templateKey' => 'career_growth'])['json']['id'];

        $nextId = $this->archiveWithNextAndReturnTheNewId($employeeClient, $regularId);

        $next = $this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$nextId}")['json'];
        self::assertFalse($next['oneOff']);
        self::assertSame('regular', $next['templateKey']);
        self::assertContains('regular-first-goal-uuid', array_column($next['goals'], 'goalUuid'));
        $openIds = $this->openAnketaIds($employeeClient);
        sort($openIds);
        $expected = [$nextId, $oneOffId];
        sort($expected);
        self::assertSame($expected, $openIds);
    }

    /**
     * An open one-off isn't the pair's chain: once the regular chain has ended (archived
     * with "skip next meeting"), a hand-created anketa restarts it — not a one-off, with
     * the ended chain's carry-forward.
     */
    public function testAPairWhoseChainEndedCanRestartItWhileAOneOffIsOpen(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('one-off-restart');
        $regularId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];
        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$regularId}/goals", [
            'goalUuid' => 'restart-goal-uuid',
            'title' => 'Still working on it',
        ]);
        $this->createAnketaAsEmployee($employeeClient, $manager['id'], ['templateKey' => 'career_growth']);
        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$regularId}/archive", ['missed' => false, 'skipNextMeeting' => true]);

        $restarted = $this->createAnketaAsEmployee($employeeClient, $manager['id'], ['periodicityDays' => null]);
        self::assertSame(201, $restarted['status']);
        $detail = $this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$restarted['json']['id']}")['json'];
        self::assertFalse($detail['oneOff']);
        self::assertContains('restart-goal-uuid', array_column($detail['goals'], 'goalUuid'));
    }

    /**
     * An archived one-off is never the carry-forward source, even when its meeting date is
     * the pair's most recent — otherwise restarting a chain would copy the one-off's
     * (empty) state and drop the chain's own open goals.
     */
    public function testCarryForwardSkipsAnArchivedOneOff(): void
    {
        [$employeeClient, , , $manager] = $this->makePair('one-off-not-carried');
        $regularId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];
        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$regularId}/goals", [
            'goalUuid' => 'chain-goal-uuid',
            'title' => 'Still working on it',
        ]);
        $oneOffId = $this->createAnketaAsEmployee($employeeClient, $manager['id'], [
            'templateKey' => 'career_growth',
            'meetingDate' => (new \DateTimeImmutable('+10 days'))->format(\DateTimeImmutable::ATOM),
        ])['json']['id'];
        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$oneOffId}/goals", [
            'goalUuid' => 'one-off-only-goal-uuid',
            'title' => 'Only in the one-off',
        ]);
        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$regularId}/archive", ['missed' => false, 'skipNextMeeting' => true]);
        $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$oneOffId}/archive", ['missed' => false, 'skipNextMeeting' => true]);

        $next = $this->createAnketaAsEmployee($employeeClient, $manager['id'], ['periodicityDays' => null]);
        $goalUuids = array_column($this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$next['json']['id']}")['json']['goals'], 'goalUuid');
        self::assertSame(['chain-goal-uuid'], $goalUuids);
    }

    /**
     * A pair whose very first anketa is still open: the hand-created second one is a
     * one-off too, and inherits the open one's periodicity rather than setting its own.
     * The pair is matched regardless of which side played employee/manager, and another
     * pair's open anketa doesn't count.
     */
    public function testASecondAnketaForANewPairIsAOneOffWithRolesSwapped(): void
    {
        [$employeeClient, $employee, $managerClient, $manager] = $this->makePair('one-off-new-pair');
        $this->createAnketaAsEmployee($employeeClient, $manager['id'], ['periodicityDays' => 7]);

        $otherManager = $this->activateUser($this->secondClient(), $this->uniqueEmail('anketa-one-off-unrelated-mgr'));
        $unrelated = $this->createAnketaAsEmployee($employeeClient, $otherManager['id']);
        self::assertFalse($this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$unrelated['json']['id']}")['json']['oneOff']);

        $swapped = $this->jsonRequest($managerClient, 'POST', '/api/anketas', [
            'counterpartId' => $employee['id'],
            'myRole' => 'employee',
            'meetingDate' => (new \DateTimeImmutable('+2 days'))->format(\DateTimeImmutable::ATOM),
            'mySealedKey' => str_repeat('e', 44),
            'counterpartSealedKey' => str_repeat('m', 44),
            'periodicityDays' => 30,
        ]);
        self::assertSame(201, $swapped['status']);

        $detail = $this->jsonRequest($managerClient, 'GET', "/api/anketas/{$swapped['json']['id']}")['json'];
        self::assertTrue($detail['oneOff']);
        self::assertSame(7, $detail['periodicityDays']);
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

        $anketa = $this->entityManager()->find(Anketa::class, $anketaId);
        \assert($anketa instanceof Anketa);
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
        $anketa = $this->entityManager()->find(Anketa::class, $anketaId);
        \assert($anketa instanceof Anketa);
        $anketa->saveDraft($anketa->getEmployee(), 'employee-draft-blob');
        $anketa->publish($anketa->getManager(), 'manager-published-blob');
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        // The employee deletes their own account for real, through the real endpoint.
        $result = $this->jsonRequest($employeeClient, 'DELETE', '/api/me', ['currentAuthKey' => str_repeat('a', 44)]);
        self::assertSame(200, $result['status']);

        $afterDeletion = $this->entityManager()->find(Anketa::class, $anketaId);
        \assert($afterDeletion instanceof Anketa);
        self::assertNull($afterDeletion->getEmployeeBlob(), 'the unpublished draft must be cleared');
        self::assertSame('manager-published-blob', $afterDeletion->getManagerBlob(), 'a published side is shared history — no cascade');

        // The manager (counterpart) now sees this anketa's counterpart as deleted.
        $listAsManager = $this->jsonRequest($managerClient, 'GET', '/api/anketas');
        self::assertTrue(self::findById($listAsManager['json'], $anketaId)['counterpartDeleted']);
    }

    /**
     * The admin-triggered counterpart to testDeletingAUserClearsTheirUnpublishedDraftButLeavesAPublishedSideUntouched()
     * above — proves AccountDeleter's shared draft-clearing behavior holds no matter
     * which of its two callers (AuthController::deleteAccount() vs.
     * AdminController::deleteUser()) triggers the deletion.
     */
    public function testAdminDeletingAUserClearsTheirUnpublishedDraftButLeavesAPublishedSideUntouched(): void
    {
        $employeeClient = static::createClient();
        $employee = $this->activateUser($employeeClient, $this->uniqueEmail('admin-delete-consequence-emp'));
        $managerClient = $this->secondClient();
        $manager = $this->activateUser($managerClient, $this->uniqueEmail('admin-delete-consequence-mgr'), admin: true);
        $anketaId = $this->createAnketaAsEmployee($employeeClient, $manager['id'])['json']['id'];

        $anketa = $this->entityManager()->find(Anketa::class, $anketaId);
        \assert($anketa instanceof Anketa);
        $anketa->saveDraft($anketa->getEmployee(), 'employee-draft-blob');
        $anketa->publish($anketa->getManager(), 'manager-published-blob');
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $block = $this->jsonRequest($managerClient, 'PUT', "/api/admin/users/{$employee['id']}/blocked", ['blocked' => true]);
        self::assertSame(200, $block['status']);
        $result = $this->jsonRequest($managerClient, 'DELETE', "/api/admin/users/{$employee['id']}");
        self::assertSame(200, $result['status']);

        $afterDeletion = $this->entityManager()->find(Anketa::class, $anketaId);
        \assert($afterDeletion instanceof Anketa);
        self::assertNull($afterDeletion->getEmployeeBlob(), 'the unpublished draft must be cleared even when an admin triggers the deletion');
        self::assertSame('manager-published-blob', $afterDeletion->getManagerBlob(), 'a published side is shared history — no cascade');
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

    /** Archives $anketaId with auto-recreation and returns the auto-created anketa's id. */
    private function archiveWithNextAndReturnTheNewId(KernelBrowser $client, string $anketaId): string
    {
        $beforeIds = array_column($this->jsonRequest($client, 'GET', '/api/anketas')['json'], 'id');
        $result = $this->jsonRequest($client, 'POST', "/api/anketas/{$anketaId}/archive", [
            'missed' => false,
            'skipNextMeeting' => false,
            'mySealedKey' => str_repeat('n', 44),
            'counterpartSealedKey' => str_repeat('o', 44),
        ]);
        self::assertSame(200, $result['status']);

        $newIds = array_values(array_diff(array_column($this->jsonRequest($client, 'GET', '/api/anketas')['json'], 'id'), $beforeIds));
        self::assertCount(1, $newIds);

        return $newIds[0];
    }

    /** @return list<string> */
    private function openAnketaIds(KernelBrowser $client): array
    {
        $open = array_filter(
            $this->jsonRequest($client, 'GET', '/api/anketas')['json'],
            static fn (array $row) => null === $row['archivedAt'],
        );

        return array_column($open, 'id');
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
