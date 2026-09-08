<?php

namespace App\Tests\Functional;

use App\Entity\Company;
use App\Entity\InviteRecord;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;

/**
 * REGISTRATION_MODE in .env is "invite" (any authenticated user may invite),
 * inherited unchanged by the test environment (.env.test doesn't override
 * it). Exercising the "admin_only" branch would need a real container
 * rebuild with a different env value — the same reason Phase 6g's manual
 * verification needed a real `docker compose up --force-recreate` between
 * configs, not something a single PHPUnit process can cheaply replicate.
 * Deliberately deferred, not silently skipped.
 */
class InviteControllerTest extends ApiTestCase
{
    public function testInviteRequiresAuthentication(): void
    {
        $client = static::createClient();
        $result = $this->jsonRequest($client, 'POST', '/api/invites', ['email' => $this->uniqueEmail('invite-target')]);

        self::assertSame(401, $result['status']);
    }

    public function testAnyAuthenticatedUserCanInviteWhenModeIsInvite(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('invite-sender'));

        $result = $this->jsonRequest($client, 'POST', '/api/invites', ['email' => $this->uniqueEmail('invite-target')]);

        self::assertSame(201, $result['status']);
    }

    public function testInviteRejectsMissingEmail(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('invite-sender-missing'));

        $result = $this->jsonRequest($client, 'POST', '/api/invites', ['email' => '']);

        self::assertSame(400, $result['status']);
    }

    public function testInviteRejectsAnEmailThatAlreadyHasAnAccount(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('invite-sender-dup'));
        $existingEmail = $this->uniqueEmail('invite-existing');

        $other = $this->secondClient();
        $this->activateUser($other, $existingEmail);

        $result = $this->jsonRequest($client, 'POST', '/api/invites', ['email' => $existingEmail]);

        self::assertSame(400, $result['status']);
        self::assertSame('That email already has an account.', $result['json']['error']);
    }

    public function testInviteIsRateLimitedAfterTooManyInvites(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('invite-rate-limit-sender'));

        // The configured limit (10/hour, config/packages/rate_limiter.php) — each of
        // these is a normal successful invite, not yet rate-limited.
        for ($i = 0; $i < 10; ++$i) {
            $result = $this->jsonRequest($client, 'POST', '/api/invites', ['email' => $this->uniqueEmail("invite-rate-limit-target-{$i}")]);
            self::assertSame(201, $result['status'], "invite {$i} should not be rate-limited yet");
        }

        $limited = $this->jsonRequest($client, 'POST', '/api/invites', ['email' => $this->uniqueEmail('invite-rate-limit-overflow')]);

        self::assertSame(429, $limited['status']);
    }

    public function testInvitesListRequires401WhenNotAuthenticated(): void
    {
        $client = static::createClient();
        $result = $this->jsonRequest($client, 'GET', '/api/admin/invites');

        self::assertSame(401, $result['status']);
    }

    public function testInvitesListRequires403ForANonAdmin(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('invites-list-non-admin'));

        $result = $this->jsonRequest($client, 'GET', '/api/admin/invites');

        self::assertSame(403, $result['status']);
    }

    public function testInvitesListShowsAFreshlySentInviteAsPendingWithItsSender(): void
    {
        $client = static::createClient();
        $admin = $this->activateUser($client, $this->uniqueEmail('invites-list-sender'), admin: true, displayName: 'Alex Morgan');
        $targetEmail = $this->uniqueEmail('invites-list-target');

        $this->jsonRequest($client, 'POST', '/api/invites', ['email' => $targetEmail]);
        $result = $this->jsonRequest($client, 'GET', '/api/admin/invites');

        self::assertSame(200, $result['status']);
        $row = current(array_filter($result['json'], fn (array $i) => $i['email'] === $targetEmail));
        self::assertNotFalse($row, 'the invite just sent should appear in the list');
        $persisted = $this->entityManager()->getRepository(InviteRecord::class)->findOneBy(['email' => $targetEmail]);
        self::assertNotNull($persisted);
        self::assertSame($persisted->getId(), $row['id']);
        self::assertSame($persisted->getExpiresAt()->format(\DATE_ATOM), $row['expiresAt']);
        self::assertSame('pending', $row['status']);
        self::assertNull($row['acceptedAt']);
        self::assertSame('Alex Morgan', $row['invitedBy']['name']);
        self::assertSame($admin['email'], $row['invitedBy']['email']);
    }

    /**
     * User::delete() anonymizes in place rather than removing the row (see its own
     * docblock) — toPayload() must render this as an explicit "sender account
     * deleted" marker, not silently show the anonymized `deleted-<id>@deleted.invalid`
     * placeholder as if it were a real, current sender address.
     */
    public function testInvitesListMarksAnInviteAsSenderDeletedOnceTheInviterIsDeleted(): void
    {
        $viewerClient = static::createClient();
        $this->activateUser($viewerClient, $this->uniqueEmail('invites-list-viewer'), admin: true);

        $senderClient = $this->secondClient();
        $sender = $this->activateUser($senderClient, $this->uniqueEmail('invites-list-deleted-sender'), admin: true);
        $targetEmail = $this->uniqueEmail('invites-list-deleted-sender-target');
        $this->jsonRequest($senderClient, 'POST', '/api/invites', ['email' => $targetEmail]);

        $senderEntity = $this->entityManager()->find(User::class, $sender['id']);
        self::assertNotNull($senderEntity);
        $senderEntity->delete();
        $this->entityManager()->flush();

        $result = $this->jsonRequest($viewerClient, 'GET', '/api/admin/invites');

        $row = current(array_filter($result['json'], fn (array $i) => $i['email'] === $targetEmail));
        self::assertNotFalse($row);
        self::assertSame(['deleted' => true], $row['invitedBy']);
    }

    /** @var list<string> */
    private array $createdCompanyIds = [];

    protected function tearDown(): void
    {
        if ([] !== $this->createdCompanyIds) {
            $connection = $this->entityManager()->getConnection();
            $placeholders = implode(',', array_fill(0, \count($this->createdCompanyIds), '?'));
            $connection->executeStatement("DELETE FROM invite_records WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM activation_tokens WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM users WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM companies WHERE id IN ({$placeholders})", $this->createdCompanyIds);
        }

        parent::tearDown();
    }

    public function testInvitesListOnlyShowsTheAdminsOwnCompany(): void
    {
        $clientA = static::createClient();
        $companyA = $this->makeCompany('Invites Co A');
        $this->activateUser($clientA, $this->uniqueEmail('invites-isolation-a'), admin: true, company: $companyA);
        $targetA = $this->uniqueEmail('invites-isolation-a-target');
        $this->jsonRequest($clientA, 'POST', '/api/invites', ['email' => $targetA]);

        $clientB = $this->secondClient();
        $companyB = $this->makeCompany('Invites Co B');
        $this->activateUser($clientB, $this->uniqueEmail('invites-isolation-b'), admin: true, company: $companyB);
        $targetB = $this->uniqueEmail('invites-isolation-b-target');
        $this->jsonRequest($clientB, 'POST', '/api/invites', ['email' => $targetB]);

        $resultA = $this->jsonRequest($clientA, 'GET', '/api/admin/invites');
        $emailsA = array_column($resultA['json'], 'email');
        self::assertContains($targetA, $emailsA);
        self::assertNotContains($targetB, $emailsA, "company A's admin must not see company B's invites");
    }

    private function makeCompany(string $name): Company
    {
        $company = new Company($name);
        $this->entityManager()->persist($company);
        $this->entityManager()->flush();
        $this->createdCompanyIds[] = $company->getId();

        return $company;
    }
}
