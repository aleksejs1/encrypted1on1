<?php

namespace App\Tests\Functional;

use App\Entity\Company;
use App\Tests\Support\ApiTestCase;

/**
 * CLOUD_MODE in .env is "0" (off), inherited unchanged by the test environment — same
 * deferral InviteControllerTest/SignupControllerTest already document for their own
 * alternate-mode gaps. testUpdateCompanySettingsRejectsDomainModeUnderCloudMode below
 * cannot exist in this suite as a real assertion for that reason; the corresponding
 * rejection in AdminController::updateCompanySettings() (registrationMode 'domain' +
 * $this->cloudMode) was instead verified for real against the live dev stack with
 * CLOUD_MODE=1 (a real 400 with errors.domain_mode_unavailable_in_cloud), not skipped
 * silently.
 */
class AdminControllerTest extends ApiTestCase
{
    public function testListUsersRequires401WhenNotAuthenticated(): void
    {
        $client = static::createClient();
        $result = $this->jsonRequest($client, 'GET', '/api/admin/users');

        self::assertSame(401, $result['status']);
    }

    public function testListUsersRequires403ForANonAdmin(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('admin-non-admin'));

        $result = $this->jsonRequest($client, 'GET', '/api/admin/users');

        self::assertSame(403, $result['status']);
        self::assertSame('Admin only.', $result['json']['error']);
    }

    public function testListUsersSucceedsForAnAdmin(): void
    {
        $client = static::createClient();
        $admin = $this->activateUser($client, $this->uniqueEmail('admin-list'), admin: true);

        $result = $this->jsonRequest($client, 'GET', '/api/admin/users');

        self::assertSame(200, $result['status']);
        $ids = array_column($result['json'], 'id');
        self::assertContains($admin['id'], $ids);
    }

    public function testListUsersIncludesEachUsersDisplayName(): void
    {
        $client = static::createClient();
        $admin = $this->activateUser($client, $this->uniqueEmail('admin-list-name'), admin: true, displayName: 'Alex Morgan');

        $result = $this->jsonRequest($client, 'GET', '/api/admin/users');

        $row = current(array_filter($result['json'], fn (array $u) => $u['id'] === $admin['id']));
        self::assertSame('Alex Morgan', $row['displayName']);
    }

    public function testSetBlockedTogglesTheFlag(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('admin-blocker'), admin: true);

        $other = $this->secondClient();
        $target = $this->activateUser($other, $this->uniqueEmail('admin-target'));

        $block = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$target['id']}/blocked", ['blocked' => true]);
        self::assertSame(200, $block['status']);
        self::assertTrue($block['json']['isBlocked']);

        $unblock = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$target['id']}/blocked", ['blocked' => false]);
        self::assertFalse($unblock['json']['isBlocked']);
    }

    /**
     * AuthController::login() already refuses a blocked account at login time —
     * this checks the other half: blocking someone with an already-open session must
     * cut that session off on its very next request too, not just at their next login
     * (see AuthSession::getCurrentUser()'s own comment for why that gap mattered).
     */
    public function testBlockingAUserInvalidatesTheirAlreadyOpenSession(): void
    {
        $adminClient = static::createClient();
        $this->activateUser($adminClient, $this->uniqueEmail('admin-blocks-live-session'), admin: true);

        $targetClient = $this->secondClient();
        $target = $this->activateUser($targetClient, $this->uniqueEmail('admin-blocked-live-session'));

        // The target's own session is live and working before being blocked.
        self::assertSame(200, $this->jsonRequest($targetClient, 'GET', '/api/me')['status']);

        $block = $this->jsonRequest($adminClient, 'PUT', "/api/admin/users/{$target['id']}/blocked", ['blocked' => true]);
        self::assertSame(200, $block['status']);

        $result = $this->jsonRequest($targetClient, 'GET', '/api/me');

        self::assertSame(401, $result['status'], 'a blocked account must lose access immediately, not just at its next login');
    }

    public function testSetBlockedRejectsBlockingYourself(): void
    {
        $client = static::createClient();
        $admin = $this->activateUser($client, $this->uniqueEmail('admin-self-block'), admin: true);

        $result = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$admin['id']}/blocked", ['blocked' => true]);

        self::assertSame(400, $result['status']);
        self::assertSame('You cannot block your own account.', $result['json']['error']);
    }

    public function testSetAdminGrantsTheAdminFlag(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('admin-granter'), admin: true);

        $other = $this->secondClient();
        $target = $this->activateUser($other, $this->uniqueEmail('admin-grantee'));
        self::assertFalse($target['isAdmin']);

        $result = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$target['id']}/admin", ['isAdmin' => true]);

        self::assertSame(200, $result['status']);
        self::assertTrue($result['json']['isAdmin']);
    }

    public function testSetBlockedReturns404ForAnUnknownUser(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('admin-unknown-target'), admin: true);

        $result = $this->jsonRequest($client, 'PUT', '/api/admin/users/00000000-0000-0000-0000-000000000000/blocked', ['blocked' => true]);

        self::assertSame(404, $result['status']);
    }

    public function testUpdateCompanySettingsRequires401WhenNotAuthenticated(): void
    {
        $client = static::createClient();
        $result = $this->jsonRequest($client, 'PUT', '/api/admin/company-settings', ['registrationMode' => 'admin_only', 'allowedEmailDomain' => '']);

        self::assertSame(401, $result['status']);
    }

    public function testUpdateCompanySettingsRequires403ForANonAdmin(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('settings-non-admin'));

        $result = $this->jsonRequest($client, 'PUT', '/api/admin/company-settings', ['registrationMode' => 'admin_only', 'allowedEmailDomain' => '']);

        self::assertSame(403, $result['status']);
    }

    public function testUpdateCompanySettingsRejectsAnInvalidRegistrationMode(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('settings-invalid-mode'), admin: true);

        $result = $this->jsonRequest($client, 'PUT', '/api/admin/company-settings', ['registrationMode' => 'nonsense', 'allowedEmailDomain' => '']);

        self::assertSame(400, $result['status']);
    }

    public function testUpdateCompanySettingsAllowsDomainModeUnderSelfHostedCloudModeOff(): void
    {
        $client = static::createClient();
        $company = $this->makeCompany('Settings Domain Co');
        $this->activateUser($client, $this->uniqueEmail('settings-domain'), admin: true, company: $company);

        $result = $this->jsonRequest($client, 'PUT', '/api/admin/company-settings', [
            'registrationMode' => 'domain',
            'allowedEmailDomain' => 'example.com',
        ]);

        self::assertSame(200, $result['status']);
        self::assertSame('domain', $result['json']['registrationMode']);
    }

    public function testUpdateCompanySettingsUpdatesBothFields(): void
    {
        $client = static::createClient();
        // A dedicated company, not the shared default one every other test in this
        // file/suite also resolves via SingleCompanyProvider — this test genuinely
        // mutates registrationMode, and leaving that change on the shared default
        // company would silently break unrelated tests later in the same run (the
        // same class of shared-mutable-state bug CompanyIsolationTest's own tearDown()
        // already exists to prevent, just for a field mutation instead of a row count).
        $company = $this->makeCompany('Settings Update Co');
        $this->activateUser($client, $this->uniqueEmail('settings-update'), admin: true, company: $company);

        $result = $this->jsonRequest($client, 'PUT', '/api/admin/company-settings', [
            'registrationMode' => 'admin_only',
            'allowedEmailDomain' => ' example.com ',
        ]);

        self::assertSame(200, $result['status']);
        self::assertSame('admin_only', $result['json']['registrationMode']);
        // Trimmed server-side.
        self::assertSame('example.com', $result['json']['allowedEmailDomain']);

        $me = $this->jsonRequest($client, 'GET', '/api/me');
        self::assertSame('admin_only', $me['json']['registrationMode']);
        self::assertSame('example.com', $me['json']['allowedEmailDomain']);
    }

    public function testUpdateCompanySettingsOnlyAffectsTheAdminsOwnCompany(): void
    {
        $clientA = static::createClient();
        $companyA = $this->makeCompany('Settings Co A');
        $this->activateUser($clientA, $this->uniqueEmail('settings-company-a'), admin: true, company: $companyA);

        $clientB = $this->secondClient();
        $companyB = $this->makeCompany('Settings Co B');
        $this->activateUser($clientB, $this->uniqueEmail('settings-company-b'), admin: true, company: $companyB);

        $this->jsonRequest($clientA, 'PUT', '/api/admin/company-settings', ['registrationMode' => 'admin_only', 'allowedEmailDomain' => '']);

        $meB = $this->jsonRequest($clientB, 'GET', '/api/me');
        self::assertSame('invite', $meB['json']['registrationMode']);
    }

    /** @var list<string> */
    private array $createdCompanyIds = [];

    protected function tearDown(): void
    {
        if ([] !== $this->createdCompanyIds) {
            $connection = $this->entityManager()->getConnection();
            $placeholders = implode(',', array_fill(0, \count($this->createdCompanyIds), '?'));
            // FK-safe order: children (tokens, users) before the company row itself —
            // same cleanup shape CompanyIsolationTest already established, needed for
            // the same reason: SingleCompanyProvider::get() fails once >1 company row
            // exists, and this suite has no per-test transaction rollback.
            $connection->executeStatement("DELETE FROM activation_tokens WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM users WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM companies WHERE id IN ({$placeholders})", $this->createdCompanyIds);
        }

        parent::tearDown();
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
