<?php

namespace App\Tests\Functional;

use App\Entity\ActivationToken;
use App\Entity\Company;
use App\Tests\Support\ApiTestCase;

/**
 * Phase D of private/cloud-service-plan.md (not tracked in git) — SeatLimitChecker's own
 * enforcement, exercised through InviteController (the fully reachable path in the
 * standard test env). SignupController calls the exact same shared service the same way,
 * but exercising *that* branch needs REGISTRATION_MODE=domain, which — per
 * SignupControllerTest's own existing docblock — needs a real container rebuild the
 * standard test env can't cheaply replicate; verified manually instead (see the plan
 * file's own verification notes), the same deferral that file already establishes for
 * its other mode-gated branches, not a new gap this item introduces.
 *
 * Same tearDown() cleanup discipline as CompanyIsolationTest/PlatformAdminControllerTest —
 * this suite creates extra Company rows.
 */
class SeatLimitTest extends ApiTestCase
{
    /** @var list<string> */
    private array $createdCompanyIds = [];

    protected function tearDown(): void
    {
        if ([] !== $this->createdCompanyIds) {
            $connection = $this->entityManager()->getConnection();
            $placeholders = implode(',', array_fill(0, \count($this->createdCompanyIds), '?'));
            $connection->executeStatement("DELETE FROM activation_tokens WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM users WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM companies WHERE id IN ({$placeholders})", $this->createdCompanyIds);
        }

        parent::tearDown();
    }

    public function testInviteSucceedsUnderTheLimit(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany(seatLimit: 2);
        $this->activateUser($client, $this->uniqueEmail('seat-under-limit'), company: $company);

        // 1 existing user, limit 2 — one more invite must still be allowed.
        $result = $this->jsonRequest($client, 'POST', '/api/invites', ['email' => $this->uniqueEmail('seat-under-limit-invitee')]);

        self::assertSame(201, $result['status']);
    }

    public function testInviteIsRejectedOnceTheLimitIsReached(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany(seatLimit: 1);
        $this->activateUser($client, $this->uniqueEmail('seat-at-limit'), company: $company);

        // 1 existing user, limit 1 — no more seats.
        $result = $this->jsonRequest($client, 'POST', '/api/invites', ['email' => $this->uniqueEmail('seat-at-limit-invitee')]);

        self::assertSame(400, $result['status']);
        self::assertSame('This company has reached its seat limit for the current plan.', $result['json']['error']);
    }

    public function testInviteIsNeverRejectedWhenSeatLimitIsNull(): void
    {
        $client = static::createClient();
        // No explicit seatLimit — null, same as the single self-hosted company's own default.
        $company = $this->makeCompany(seatLimit: null);
        $this->activateUser($client, $this->uniqueEmail('seat-unlimited'), company: $company);

        $result = $this->jsonRequest($client, 'POST', '/api/invites', ['email' => $this->uniqueEmail('seat-unlimited-invitee')]);

        self::assertSame(201, $result['status']);
    }

    public function testADeletedAccountFreesUpItsSeat(): void
    {
        $adminClient = static::createClient();
        $company = $this->makeCompany(seatLimit: 2);
        $this->activateUser($adminClient, $this->uniqueEmail('seat-freed-admin'), company: $company);
        $target = $this->activateUser($this->secondClient(), $this->uniqueEmail('seat-freed-target'), company: $company);

        // Both seats taken — the next invite must be rejected.
        $full = $this->jsonRequest($adminClient, 'POST', '/api/invites', ['email' => $this->uniqueEmail('seat-freed-invitee-1')]);
        self::assertSame(400, $full['status']);

        // A raw delete (anonymization-in-place, User::delete()'s own shape) frees the seat.
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE users SET deletedAt = ? WHERE id = ?',
            [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $target['id']],
        );

        $freed = $this->jsonRequest($adminClient, 'POST', '/api/invites', ['email' => $this->uniqueEmail('seat-freed-invitee-2')]);
        self::assertSame(201, $freed['status'], 'a deleted account must no longer count against the seat limit');
    }

    /**
     * The real endpoint (AuthController::deleteAccount()), not just the raw-SQL
     * simulation testADeletedAccountFreesUpItsSeat() above uses to isolate
     * SeatLimitChecker's own query — this proves the actual self-service flow really
     * does free the seat, end to end.
     */
    public function testSelfServiceAccountDeletionFreesUpItsSeat(): void
    {
        $adminClient = static::createClient();
        $company = $this->makeCompany(seatLimit: 2);
        $this->activateUser($adminClient, $this->uniqueEmail('seat-self-delete-admin'), company: $company);
        $targetClient = $this->secondClient();
        $this->activateUser($targetClient, $this->uniqueEmail('seat-self-delete-target'), company: $company);

        // Both seats taken — the next invite must be rejected.
        $full = $this->jsonRequest($adminClient, 'POST', '/api/invites', ['email' => $this->uniqueEmail('seat-self-delete-invitee-1')]);
        self::assertSame(400, $full['status']);

        $delete = $this->jsonRequest($targetClient, 'DELETE', '/api/me', ['currentAuthKey' => str_repeat('a', 44)]);
        self::assertSame(200, $delete['status']);

        $freed = $this->jsonRequest($adminClient, 'POST', '/api/invites', ['email' => $this->uniqueEmail('seat-self-delete-invitee-2')]);
        self::assertSame(201, $freed['status'], 'a real self-service account deletion must free its seat');
    }

    /**
     * The admin-triggered counterpart: a departed employee left blocked (not deleted)
     * still occupies a seat by design (SeatLimitChecker's own docblock) — this proves
     * AdminController::deleteUser() is the way a company admin actually frees it.
     */
    public function testAdminDeletingABlockedUserFreesUpItsSeat(): void
    {
        $adminClient = static::createClient();
        $company = $this->makeCompany(seatLimit: 2);
        $this->activateUser($adminClient, $this->uniqueEmail('seat-admin-delete-admin'), admin: true, company: $company);
        $target = $this->activateUser($this->secondClient(), $this->uniqueEmail('seat-admin-delete-target'), company: $company);

        // Both seats taken — the next invite must be rejected.
        $full = $this->jsonRequest($adminClient, 'POST', '/api/invites', ['email' => $this->uniqueEmail('seat-admin-delete-invitee-1')]);
        self::assertSame(400, $full['status']);

        // Blocking alone must not free the seat (SeatLimitChecker's own documented rule).
        $block = $this->jsonRequest($adminClient, 'PUT', "/api/admin/users/{$target['id']}/blocked", ['blocked' => true]);
        self::assertSame(200, $block['status']);
        $stillFull = $this->jsonRequest($adminClient, 'POST', '/api/invites', ['email' => $this->uniqueEmail('seat-admin-delete-invitee-2')]);
        self::assertSame(400, $stillFull['status'], 'a merely blocked user must still occupy its seat');

        $delete = $this->jsonRequest($adminClient, 'DELETE', "/api/admin/users/{$target['id']}");
        self::assertSame(200, $delete['status']);

        $freed = $this->jsonRequest($adminClient, 'POST', '/api/invites', ['email' => $this->uniqueEmail('seat-admin-delete-invitee-3')]);
        self::assertSame(201, $freed['status'], 'an admin-deleted blocked account must no longer count against the seat limit');
    }

    /**
     * SeatLimitChecker::hasReachedLimit() used to count only already-activated User rows
     * — headcount only grew once an invitee actually activated, so a burst of invites
     * issued back-to-back (each checked against the same not-yet-grown headcount) could
     * land a company past its seat limit the moment they all activated. This checks the
     * fix: an outstanding, unactivated invite must itself occupy a seat.
     */
    public function testPendingInvitesCountAgainstTheSeatLimitBeforeTheyAreActivated(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany(seatLimit: 2);
        $this->activateUser($client, $this->uniqueEmail('seat-pending-admin'), company: $company);

        // 1 existing user, limit 2 — one seat left; the first invite takes it.
        $first = $this->jsonRequest($client, 'POST', '/api/invites', ['email' => $this->uniqueEmail('seat-pending-invitee-1')]);
        self::assertSame(201, $first['status']);

        // Nobody has activated yet, but the outstanding invite already occupies the
        // remaining seat — a second invite must be rejected too, not just a second real user.
        $second = $this->jsonRequest($client, 'POST', '/api/invites', ['email' => $this->uniqueEmail('seat-pending-invitee-2')]);
        self::assertSame(400, $second['status']);
        self::assertSame('This company has reached its seat limit for the current plan.', $second['json']['error']);
    }

    public function testAnExpiredPendingInviteNoLongerCountsAgainstTheSeatLimit(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany(seatLimit: 2);
        $this->activateUser($client, $this->uniqueEmail('seat-expired-pending-admin'), company: $company);

        // Re-fetched by id, same reasoning as activateUser()'s own docblock: the
        // activateUser() call above already made a real HTTP request through $client,
        // which clears the entity manager's identity map and leaves $company detached.
        $company = $this->entityManager()->find(Company::class, $company->getId());
        self::assertNotNull($company);

        // A stale, expired invite left over from before — same shape issue() produces,
        // just already past its TTL (issue() itself always sets a real future one).
        $expiredToken = new ActivationToken(
            bin2hex(random_bytes(32)),
            $this->uniqueEmail('seat-expired-pending-invitee'),
            $company,
            false,
            new \DateTimeImmutable('-1 minute'),
        );
        $this->entityManager()->persist($expiredToken);
        $this->entityManager()->flush();

        $result = $this->jsonRequest($client, 'POST', '/api/invites', ['email' => $this->uniqueEmail('seat-expired-pending-invitee-2')]);

        self::assertSame(201, $result['status'], 'an expired pending invite must not occupy a seat');
    }

    private function makeCompany(?int $seatLimit): Company
    {
        $company = new Company('Seat Limit Co', seatLimit: $seatLimit);
        $this->entityManager()->persist($company);
        $this->entityManager()->flush();
        $this->createdCompanyIds[] = $company->getId();

        return $company;
    }
}
