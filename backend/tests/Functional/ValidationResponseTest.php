<?php

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

class ValidationResponseTest extends ApiTestCase
{
    public function testValidationReturns400WithViolationsStructure(): void
    {
        $client = static::createClient();

        // Send an invalid login request (empty payload)
        $result = $this->jsonRequest($client, 'POST', '/api/login', []);

        self::assertSame(400, $result['status']);
        self::assertIsArray($result['json']);
        self::assertArrayHasKey('error', $result['json']);
        self::assertArrayHasKey('violations', $result['json']);
        self::assertIsArray($result['json']['violations']);
        self::assertNotEmpty($result['json']['violations']);

        foreach ($result['json']['violations'] as $violation) {
            self::assertArrayHasKey('property', $violation);
            self::assertArrayHasKey('message', $violation);
            self::assertIsString($violation['property']);
            self::assertIsString($violation['message']);
        }
    }

    public function testValidationReturns400OnInvalidEmailFormat(): void
    {
        $client = static::createClient();

        $result = $this->jsonRequest($client, 'POST', '/api/login', [
            'email' => 'not-an-email',
            'authHash' => 'somehash',
        ]);

        self::assertSame(400, $result['status']);
        self::assertIsArray($result['json']);
        self::assertArrayHasKey('error', $result['json']);
        self::assertArrayHasKey('violations', $result['json']);
        $properties = array_column($result['json']['violations'], 'property');
        self::assertContains('email', $properties);
    }

    public function testCreateAnketaRejectsInvalidMeetingDate(): void
    {
        [$client, , $counterpart] = $this->setupPair('create-anketa-date');

        $result = $this->jsonRequest($client, 'POST', '/api/anketas', [
            'counterpartId' => $counterpart['id'],
            'myRole' => 'employee',
            'meetingDate' => 'not-a-valid-date',
            'mySealedKey' => str_repeat('k', 44),
            'counterpartSealedKey' => str_repeat('k', 44),
            'periodicityDays' => 14,
        ]);

        self::assertSame(400, $result['status']);
        self::assertIsArray($result['json']);
        $properties = array_column($result['json']['violations'], 'property');
        self::assertContains('meetingDate', $properties);
    }

    public function testRescheduleRejectsInvalidMeetingDate(): void
    {
        [$client, $anketaId] = $this->setupAnketa('reschedule-date');

        $result = $this->jsonRequest($client, 'PUT', "/api/anketas/{$anketaId}/meeting-date", [
            'meetingDate' => 'not-a-date',
        ]);

        self::assertSame(400, $result['status']);
        self::assertIsArray($result['json']);
        $properties = array_column($result['json']['violations'], 'property');
        self::assertContains('meetingDate', $properties);
    }

    public function testArchiveRejectsInvalidNextMeetingDate(): void
    {
        [$client, $anketaId] = $this->setupAnketa('archive-date');

        $result = $this->jsonRequest($client, 'POST', "/api/anketas/{$anketaId}/archive", [
            'missed' => false,
            'skipNextMeeting' => false,
            'nextMeetingDate' => 'not-a-date',
            'mySealedKey' => str_repeat('n', 44),
            'counterpartSealedKey' => str_repeat('o', 44),
        ]);

        self::assertSame(400, $result['status']);
        self::assertIsArray($result['json']);
        $properties = array_column($result['json']['violations'], 'property');
        self::assertContains('nextMeetingDate', $properties);
    }

    /**
     * The counterpart to testArchiveRejectsInvalidNextMeetingDate() above: when
     * skipNextMeeting is true, nextMeetingDate is never read by the archive flow, so a
     * garbage value in it must be silently ignored, not rejected.
     */
    public function testArchiveIgnoresInvalidNextMeetingDateWhenSkippingNextMeeting(): void
    {
        [$client, $anketaId] = $this->setupAnketa('archive-skip-date');

        $result = $this->jsonRequest($client, 'POST', "/api/anketas/{$anketaId}/archive", [
            'missed' => false,
            'skipNextMeeting' => true,
            'nextMeetingDate' => 'not-a-date',
        ]);

        self::assertSame(200, $result['status']);
    }

    public function testCreateGoalRejectsInvalidTargetDate(): void
    {
        [$client, $anketaId] = $this->setupAnketa('create-goal-date');

        $result = $this->jsonRequest($client, 'POST', "/api/anketas/{$anketaId}/goals", [
            'goalUuid' => 'goal-uuid-date-test',
            'title' => 'Test Goal',
            'targetDate' => 'invalid-date',
        ]);

        self::assertSame(400, $result['status']);
        self::assertIsArray($result['json']);
        $properties = array_column($result['json']['violations'], 'property');
        self::assertContains('targetDate', $properties);

        // Non-string scalar type check
        $resultScalar = $this->jsonRequest($client, 'POST', "/api/anketas/{$anketaId}/goals", [
            'goalUuid' => 'goal-uuid-scalar-test',
            'title' => 'Test Goal Scalar',
            'targetDate' => 12345,
        ]);

        self::assertSame(400, $resultScalar['status']);
    }

    public function testUpdateGoalRejectsNullTitleAndNullStatus(): void
    {
        [$client, $anketaId] = $this->setupAnketa('update-goal-null');

        $goalCreated = $this->jsonRequest($client, 'POST', "/api/anketas/{$anketaId}/goals", [
            'goalUuid' => 'goal-uuid-update-test',
            'title' => 'Initial Title',
        ]);
        self::assertSame(201, $goalCreated['status']);
        $goalId = $goalCreated['json']['id'];

        $result = $this->jsonRequest($client, 'PUT', "/api/anketas/{$anketaId}/goals/{$goalId}", [
            'title' => null,
            'status' => null,
        ]);

        self::assertSame(400, $result['status']);
        self::assertIsArray($result['json']);
        $properties = array_column($result['json']['violations'], 'property');
        self::assertContains('title', $properties);
        self::assertContains('status', $properties);
    }

    public function testUpdateGoalRejectsInvalidTargetDate(): void
    {
        [$client, $anketaId] = $this->setupAnketa('update-goal-date');

        $goalCreated = $this->jsonRequest($client, 'POST', "/api/anketas/{$anketaId}/goals", [
            'goalUuid' => 'goal-uuid-update-date-test',
            'title' => 'Initial Title',
        ]);
        self::assertSame(201, $goalCreated['status']);
        $goalId = $goalCreated['json']['id'];

        $result = $this->jsonRequest($client, 'PUT', "/api/anketas/{$anketaId}/goals/{$goalId}", [
            'targetDate' => 'not-a-valid-date',
        ]);

        self::assertSame(400, $result['status']);
        self::assertIsArray($result['json']);
        $properties = array_column($result['json']['violations'], 'property');
        self::assertContains('targetDate', $properties);
    }

    /** A title of only whitespace trims to empty and must be rejected the same as an empty string. */
    public function testUpdateGoalRejectsAWhitespaceOnlyTitle(): void
    {
        [$client, $anketaId] = $this->setupAnketa('update-goal-blank-title');

        $goalCreated = $this->jsonRequest($client, 'POST', "/api/anketas/{$anketaId}/goals", [
            'goalUuid' => 'goal-uuid-blank-title-test',
            'title' => 'Initial Title',
        ]);
        self::assertSame(201, $goalCreated['status']);
        $goalId = $goalCreated['json']['id'];

        $result = $this->jsonRequest($client, 'PUT', "/api/anketas/{$anketaId}/goals/{$goalId}", [
            'title' => '   ',
        ]);

        self::assertSame(400, $result['status']);
        self::assertIsArray($result['json']);
        $properties = array_column($result['json']['violations'], 'property');
        self::assertContains('title', $properties);
    }

    /**
     * The error message for an invalid status must actually list the valid ones
     * (the %statuses% placeholder must be substituted, not dropped).
     */
    public function testUpdateGoalInvalidStatusMessageListsValidStatuses(): void
    {
        [$client, $anketaId] = $this->setupAnketa('update-goal-status-message');

        $goalCreated = $this->jsonRequest($client, 'POST', "/api/anketas/{$anketaId}/goals", [
            'goalUuid' => 'goal-uuid-status-message-test',
            'title' => 'Initial Title',
        ]);
        self::assertSame(201, $goalCreated['status']);
        $goalId = $goalCreated['json']['id'];

        $result = $this->jsonRequest($client, 'PUT', "/api/anketas/{$anketaId}/goals/{$goalId}", [
            'status' => 'not-a-real-status',
        ]);

        self::assertSame(400, $result['status']);
        self::assertIsArray($result['json']);
        $violation = self::findViolation($result['json']['violations'], 'status');
        self::assertStringNotContainsString('%statuses%', $violation['message']);
        self::assertStringContainsString('in_progress', $violation['message']);
    }

    public function testSaveVersionedBlobRejectsNegativeExpectedVersion(): void
    {
        [$client, $anketaId] = $this->setupAnketa('negative-version');

        $result = $this->jsonRequest($client, 'PUT', "/api/anketas/{$anketaId}/comments", [
            'blob' => 'some-comments',
            'expectedVersion' => -1,
        ]);

        self::assertSame(400, $result['status']);
        self::assertIsArray($result['json']);
        $properties = array_column($result['json']['violations'], 'property');
        self::assertContains('expectedVersion', $properties);
    }

    /**
     * @return array{0: KernelBrowser, 1: array{id: string, email: string, isAdmin: bool}, 2: array{id: string, email: string, isAdmin: bool}}
     */
    /**
     * @param array<int, array{property: string, message: string}> $violations
     *
     * @return array{property: string, message: string}
     */
    private static function findViolation(array $violations, string $property): array
    {
        foreach ($violations as $violation) {
            if ($violation['property'] === $property) {
                return $violation;
            }
        }
        self::fail("no violation for property \"{$property}\" found");
    }

    /**
     * @return array{0: KernelBrowser, 1: array{id: string, email: string, isAdmin: bool}, 2: array{id: string, email: string, isAdmin: bool}}
     */
    private function setupPair(string $label): array
    {
        $employeeClient = static::createClient();
        $employee = $this->activateUser($employeeClient, $this->uniqueEmail("val-{$label}-emp"));
        $managerClient = $this->secondClient();
        $manager = $this->activateUser($managerClient, $this->uniqueEmail("val-{$label}-mgr"));

        return [$employeeClient, $employee, $manager];
    }

    /**
     * @return array{0: KernelBrowser, 1: string}
     */
    private function setupAnketa(string $label): array
    {
        [$client, , $manager] = $this->setupPair($label);

        $created = $this->jsonRequest($client, 'POST', '/api/anketas', [
            'counterpartId' => $manager['id'],
            'myRole' => 'employee',
            'meetingDate' => (new \DateTimeImmutable('+1 day'))->format(\DateTimeImmutable::ATOM),
            'mySealedKey' => str_repeat('e', 44),
            'counterpartSealedKey' => str_repeat('m', 44),
            'periodicityDays' => 30,
        ]);

        self::assertSame(201, $created['status']);

        return [$client, $created['json']['id']];
    }
}
