<?php

namespace App\Tests\Functional;

use App\Entity\Company;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;

/**
 * Phase C of private/cloud-service-plan.md (not tracked in git) — PlatformAdminController
 * is the one deliberate exception to the company isolation CompanyIsolationTest (Phase A)
 * otherwise proves everywhere else, so this suite proves both directions: a mere company
 * admin gets the same 403 anyone else would, and a genuine platform admin really does see
 * across every company.
 *
 * Same tearDown() cleanup discipline as CompanyIsolationTest, for the same reason: this
 * suite creates extra Company rows, which would otherwise break every other test file's
 * SingleCompanyProvider-based shortcut for the rest of the same suite run.
 */
class PlatformAdminControllerTest extends ApiTestCase
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

    public function testCompaniesListRequiresAuthentication(): void
    {
        $client = static::createClient();

        $result = $this->jsonRequest($client, 'GET', '/api/platform-admin/companies');

        self::assertSame(401, $result['status']);
    }

    public function testCompanyAdminIsRejectedFromPlatformAdminRoutes(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('platform-admin-mere-admin'), admin: true);

        $companies = $this->jsonRequest($client, 'GET', '/api/platform-admin/companies');
        $users = $this->jsonRequest($client, 'GET', '/api/platform-admin/users');

        self::assertSame(403, $companies['status']);
        self::assertSame('Platform admin only.', $companies['json']['error']);
        self::assertSame(403, $users['status']);
    }

    public function testPlatformAdminSeesUsersAcrossEveryCompany(): void
    {
        $platformAdminClient = static::createClient();
        $companyA = $this->makeCompany('Company A');
        $companyB = $this->makeCompany('Company B');
        $platformAdmin = $this->activateUser($platformAdminClient, $this->uniqueEmail('platform-admin-viewer'), company: $companyA);
        $this->makePlatformAdmin($platformAdmin['id']);
        $otherCompanyClient = $this->secondClient();
        $otherCompanyUser = $this->activateUser($otherCompanyClient, $this->uniqueEmail('platform-admin-other-co'), company: $companyB);

        $result = $this->jsonRequest($platformAdminClient, 'GET', '/api/platform-admin/users');

        self::assertSame(200, $result['status']);
        $ids = array_column($result['json'], 'id');
        self::assertContains($platformAdmin['id'], $ids);
        self::assertContains($otherCompanyUser['id'], $ids, 'a platform admin must see users from every company, unlike AdminController');
    }

    public function testPlatformAdminUsersListIncludesEachUsersDisplayName(): void
    {
        $platformAdminClient = static::createClient();
        $platformAdmin = $this->activateUser($platformAdminClient, $this->uniqueEmail('platform-admin-name'), displayName: 'Alex Morgan');
        $this->makePlatformAdmin($platformAdmin['id']);

        $result = $this->jsonRequest($platformAdminClient, 'GET', '/api/platform-admin/users');

        $row = current(array_filter($result['json'], fn (array $u) => $u['id'] === $platformAdmin['id']));
        self::assertSame('Alex Morgan', $row['displayName']);
    }

    public function testPlatformAdminSeesEveryCompanyWithACorrectUserCount(): void
    {
        $platformAdminClient = static::createClient();
        $companyA = $this->makeCompany('Company A');
        $companyB = $this->makeCompany('Company B');
        $platformAdmin = $this->activateUser($platformAdminClient, $this->uniqueEmail('platform-admin-companies'), company: $companyA);
        $this->makePlatformAdmin($platformAdmin['id']);
        $secondClient = $this->secondClient();
        $this->activateUser($secondClient, $this->uniqueEmail('platform-admin-companies-b1'), company: $companyB);

        $result = $this->jsonRequest($platformAdminClient, 'GET', '/api/platform-admin/companies');

        self::assertSame(200, $result['status']);
        $byId = [];
        foreach ($result['json'] as $row) {
            $byId[$row['id']] = $row;
        }
        self::assertArrayHasKey($companyA->getId(), $byId);
        self::assertArrayHasKey($companyB->getId(), $byId);
        self::assertSame(1, $byId[$companyA->getId()]['userCount']);
        self::assertSame(1, $byId[$companyB->getId()]['userCount']);
        // Billing fields (Phase D, private/cloud-service-plan.md, not tracked in git).
        self::assertSame('free', $byId[$companyA->getId()]['planTier']);
        self::assertNull($byId[$companyA->getId()]['seatLimit']);
        self::assertSame('active', $byId[$companyA->getId()]['subscriptionStatus']);
        self::assertFalse($byId[$companyA->getId()]['isSuspended']);
    }

    public function testPlatformAdminCanSuspendAndUnsuspendACompany(): void
    {
        $platformAdminClient = static::createClient();
        $companyA = $this->makeCompany('Company A');
        $companyB = $this->makeCompany('Company B');
        $platformAdmin = $this->activateUser($platformAdminClient, $this->uniqueEmail('platform-admin-suspender'), company: $companyA);
        $this->makePlatformAdmin($platformAdmin['id']);

        $suspend = $this->jsonRequest($platformAdminClient, 'PUT', "/api/platform-admin/companies/{$companyB->getId()}/suspended", ['suspended' => true]);
        self::assertSame(200, $suspend['status']);
        self::assertTrue($suspend['json']['isSuspended']);

        $unsuspend = $this->jsonRequest($platformAdminClient, 'PUT', "/api/platform-admin/companies/{$companyB->getId()}/suspended", ['suspended' => false]);
        self::assertSame(200, $unsuspend['status']);
        self::assertFalse($unsuspend['json']['isSuspended']);
    }

    public function testSetCompanySuspendedRejectsAMissingField(): void
    {
        $platformAdminClient = static::createClient();
        $company = $this->makeCompany('Company A');
        $platformAdmin = $this->activateUser($platformAdminClient, $this->uniqueEmail('platform-admin-suspend-missing-field'), company: $company);
        $this->makePlatformAdmin($platformAdmin['id']);

        $result = $this->jsonRequest($platformAdminClient, 'PUT', "/api/platform-admin/companies/{$company->getId()}/suspended", []);

        self::assertSame(400, $result['status']);
    }

    public function testSetCompanySuspendedReturns404ForAnUnknownCompany(): void
    {
        $platformAdminClient = static::createClient();
        $company = $this->makeCompany('Company A');
        $platformAdmin = $this->activateUser($platformAdminClient, $this->uniqueEmail('platform-admin-suspend-unknown'), company: $company);
        $this->makePlatformAdmin($platformAdmin['id']);

        $result = $this->jsonRequest($platformAdminClient, 'PUT', '/api/platform-admin/companies/00000000-0000-0000-0000-000000000000/suspended', ['suspended' => true]);

        self::assertSame(404, $result['status']);
    }

    /**
     * The company listing's own userCount must agree with what SeatLimitChecker actually
     * enforces — otherwise a departed, anonymized account would keep showing against a
     * company's seat total forever, even though InviteController would still accept a
     * new invite for that freed seat.
     */
    public function testPlatformAdminCompanyUserCountExcludesDeletedUsers(): void
    {
        $platformAdminClient = static::createClient();
        $company = $this->makeCompany('Company A');
        $platformAdmin = $this->activateUser($platformAdminClient, $this->uniqueEmail('platform-admin-usercount-deleted'), company: $company);
        $this->makePlatformAdmin($platformAdmin['id']);
        $target = $this->activateUser($this->secondClient(), $this->uniqueEmail('platform-admin-usercount-deleted-target'), company: $company);
        $targetEntity = $this->entityManager()->find(User::class, $target['id']);
        self::assertNotNull($targetEntity);
        $targetEntity->delete();
        $this->entityManager()->flush();

        $result = $this->jsonRequest($platformAdminClient, 'GET', '/api/platform-admin/companies');

        $row = current(array_filter($result['json'], fn (array $c) => $c['id'] === $company->getId()));
        self::assertSame(1, $row['userCount'], 'the deleted user must not count towards userCount');
    }

    public function testPlatformAdminCanChangeACompanysSeatLimit(): void
    {
        $platformAdminClient = static::createClient();
        $companyA = $this->makeCompany('Company A');
        $companyB = $this->makeCompany('Company B');
        $platformAdmin = $this->activateUser($platformAdminClient, $this->uniqueEmail('platform-admin-seat-setter'), company: $companyA);
        $this->makePlatformAdmin($platformAdmin['id']);

        $result = $this->jsonRequest($platformAdminClient, 'PUT', "/api/platform-admin/companies/{$companyB->getId()}/seat-limit", ['seatLimit' => 25]);

        self::assertSame(200, $result['status']);
        self::assertSame(25, $result['json']['seatLimit']);
    }

    public function testPlatformAdminCanSetACompanysSeatLimitBackToUnlimited(): void
    {
        $platformAdminClient = static::createClient();
        $company = $this->makeCompany('Company A', seatLimit: 10);
        $platformAdmin = $this->activateUser($platformAdminClient, $this->uniqueEmail('platform-admin-seat-unlimited'), company: $company);
        $this->makePlatformAdmin($platformAdmin['id']);

        $result = $this->jsonRequest($platformAdminClient, 'PUT', "/api/platform-admin/companies/{$company->getId()}/seat-limit", ['seatLimit' => null]);

        self::assertSame(200, $result['status']);
        self::assertNull($result['json']['seatLimit']);
    }

    public function testSetSeatLimitRejectsAMissingField(): void
    {
        $platformAdminClient = static::createClient();
        $company = $this->makeCompany('Company A');
        $platformAdmin = $this->activateUser($platformAdminClient, $this->uniqueEmail('platform-admin-seat-missing-field'), company: $company);
        $this->makePlatformAdmin($platformAdmin['id']);

        $result = $this->jsonRequest($platformAdminClient, 'PUT', "/api/platform-admin/companies/{$company->getId()}/seat-limit", []);

        self::assertSame(400, $result['status']);
    }

    public function testSetSeatLimitRejectsANonPositiveValue(): void
    {
        $platformAdminClient = static::createClient();
        $company = $this->makeCompany('Company A');
        $platformAdmin = $this->activateUser($platformAdminClient, $this->uniqueEmail('platform-admin-seat-invalid'), company: $company);
        $this->makePlatformAdmin($platformAdmin['id']);

        $result = $this->jsonRequest($platformAdminClient, 'PUT', "/api/platform-admin/companies/{$company->getId()}/seat-limit", ['seatLimit' => 0]);

        self::assertSame(400, $result['status']);
    }

    public function testSetSeatLimitReturns404ForAnUnknownCompany(): void
    {
        $platformAdminClient = static::createClient();
        $company = $this->makeCompany('Company A');
        $platformAdmin = $this->activateUser($platformAdminClient, $this->uniqueEmail('platform-admin-seat-unknown'), company: $company);
        $this->makePlatformAdmin($platformAdmin['id']);

        $result = $this->jsonRequest($platformAdminClient, 'PUT', '/api/platform-admin/companies/00000000-0000-0000-0000-000000000000/seat-limit', ['seatLimit' => 5]);

        self::assertSame(404, $result['status']);
    }

    public function testSetSeatLimitRequires403ForAMereCompanyAdmin(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Company A');
        $this->activateUser($client, $this->uniqueEmail('platform-admin-seat-mere-admin'), admin: true, company: $company);

        $result = $this->jsonRequest($client, 'PUT', "/api/platform-admin/companies/{$company->getId()}/seat-limit", ['seatLimit' => 5]);

        self::assertSame(403, $result['status']);
    }

    public function testPlatformAdminCanBlockAUserFromAnyCompany(): void
    {
        $platformAdminClient = static::createClient();
        $companyA = $this->makeCompany('Company A');
        $companyB = $this->makeCompany('Company B');
        $platformAdmin = $this->activateUser($platformAdminClient, $this->uniqueEmail('platform-admin-blocker'), company: $companyA);
        $this->makePlatformAdmin($platformAdmin['id']);
        $target = $this->activateUser($this->secondClient(), $this->uniqueEmail('platform-admin-block-target'), company: $companyB);

        $result = $this->jsonRequest($platformAdminClient, 'PUT', "/api/platform-admin/users/{$target['id']}/blocked", ['blocked' => true]);

        self::assertSame(200, $result['status']);
        self::assertTrue($result['json']['isBlocked']);
    }

    public function testPlatformAdminCannotBlockOwnAccount(): void
    {
        $platformAdminClient = static::createClient();
        $company = $this->makeCompany('Company A');
        $platformAdmin = $this->activateUser($platformAdminClient, $this->uniqueEmail('platform-admin-self-block'), company: $company);
        $this->makePlatformAdmin($platformAdmin['id']);

        $result = $this->jsonRequest($platformAdminClient, 'PUT', "/api/platform-admin/users/{$platformAdmin['id']}/blocked", ['blocked' => true]);

        self::assertSame(400, $result['status']);
    }

    /**
     * User::delete() forces isBlocked=true "for defense-in-depth" — this proves that
     * defense holds against a platform admin too, not just a company admin
     * (AdminControllerTest::testSetBlockedRejectsAnAlreadyDeletedUser covers that half).
     */
    public function testPlatformAdminCannotUnblockAnAlreadyDeletedUser(): void
    {
        $platformAdminClient = static::createClient();
        $company = $this->makeCompany('Company A');
        $platformAdmin = $this->activateUser($platformAdminClient, $this->uniqueEmail('platform-admin-unblock-deleted'), company: $company);
        $this->makePlatformAdmin($platformAdmin['id']);
        $target = $this->activateUser($this->secondClient(), $this->uniqueEmail('platform-admin-unblock-deleted-target'), company: $company);
        $this->jsonRequest($platformAdminClient, 'PUT', "/api/platform-admin/users/{$target['id']}/blocked", ['blocked' => true]);
        $targetEntity = $this->entityManager()->find(User::class, $target['id']);
        self::assertNotNull($targetEntity);
        $targetEntity->delete();
        $this->entityManager()->flush();

        $result = $this->jsonRequest($platformAdminClient, 'PUT', "/api/platform-admin/users/{$target['id']}/blocked", ['blocked' => false]);

        self::assertSame(400, $result['status']);
        self::assertSame('This account has already been deleted.', $result['json']['error']);
    }

    public function testPlatformAdminCannotGrantPlatformAdminToAnAlreadyDeletedUser(): void
    {
        $platformAdminClient = static::createClient();
        $company = $this->makeCompany('Company A');
        $platformAdmin = $this->activateUser($platformAdminClient, $this->uniqueEmail('platform-admin-grant-deleted'), company: $company);
        $this->makePlatformAdmin($platformAdmin['id']);
        $target = $this->activateUser($this->secondClient(), $this->uniqueEmail('platform-admin-grant-deleted-target'), company: $company);
        $targetEntity = $this->entityManager()->find(User::class, $target['id']);
        self::assertNotNull($targetEntity);
        $targetEntity->delete();
        $this->entityManager()->flush();

        $result = $this->jsonRequest($platformAdminClient, 'PUT', "/api/platform-admin/users/{$target['id']}/platform-admin", ['isPlatformAdmin' => true]);

        self::assertSame(400, $result['status']);
        self::assertSame('This account has already been deleted.', $result['json']['error']);
    }

    public function testPlatformAdminCanGrantAndRevokePlatformAdminOnAnotherUser(): void
    {
        $platformAdminClient = static::createClient();
        $company = $this->makeCompany('Company A');
        $platformAdmin = $this->activateUser($platformAdminClient, $this->uniqueEmail('platform-admin-granter'), company: $company);
        $this->makePlatformAdmin($platformAdmin['id']);
        $target = $this->activateUser($this->secondClient(), $this->uniqueEmail('platform-admin-grantee'), company: $company);

        $grant = $this->jsonRequest($platformAdminClient, 'PUT', "/api/platform-admin/users/{$target['id']}/platform-admin", ['isPlatformAdmin' => true]);
        self::assertSame(200, $grant['status']);
        self::assertTrue($grant['json']['isPlatformAdmin']);

        $revoke = $this->jsonRequest($platformAdminClient, 'PUT', "/api/platform-admin/users/{$target['id']}/platform-admin", ['isPlatformAdmin' => false]);
        self::assertSame(200, $revoke['status']);
        self::assertFalse($revoke['json']['isPlatformAdmin']);
    }

    public function testSetPlatformAdminRejectsAMissingField(): void
    {
        $platformAdminClient = static::createClient();
        $company = $this->makeCompany('Company A');
        $platformAdmin = $this->activateUser($platformAdminClient, $this->uniqueEmail('platform-admin-missing-field'), company: $company);
        $this->makePlatformAdmin($platformAdmin['id']);
        $target = $this->activateUser($this->secondClient(), $this->uniqueEmail('platform-admin-missing-field-target'), company: $company);

        $result = $this->jsonRequest($platformAdminClient, 'PUT', "/api/platform-admin/users/{$target['id']}/platform-admin", []);

        self::assertSame(400, $result['status']);
    }

    private function makeCompany(string $name, ?int $seatLimit = null): Company
    {
        $company = new Company($name, seatLimit: $seatLimit);
        $this->entityManager()->persist($company);
        $this->entityManager()->flush();
        $this->createdCompanyIds[] = $company->getId();

        return $company;
    }

    /**
     * Mirrors app:grant-platform-admin's own mutation, directly, since there's no HTTP
     * way to become the *first* platform admin in a test (same reasoning
     * ApiTestCase::activateUser() already documents for reaching into the entity
     * manager instead of a real command invocation).
     */
    private function makePlatformAdmin(string $userId): void
    {
        $user = $this->entityManager()->find(User::class, $userId);
        self::assertNotNull($user);
        $user->setPlatformAdmin(true);
        $this->entityManager()->flush();
    }
}
