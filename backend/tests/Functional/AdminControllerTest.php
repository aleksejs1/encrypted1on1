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

    /**
     * User::delete() forces isBlocked=true "for defense-in-depth" — this proves that
     * defense actually holds: a company admin must not be able to un-block (or
     * re-admin) a row deleteUser() already anonymized.
     */
    public function testSetBlockedRejectsAnAlreadyDeletedUser(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('admin-set-blocked-deleted'), admin: true);
        $target = $this->activateUser($this->secondClient(), $this->uniqueEmail('admin-set-blocked-deleted-target'));
        $this->jsonRequest($client, 'PUT', "/api/admin/users/{$target['id']}/blocked", ['blocked' => true]);
        $this->jsonRequest($client, 'DELETE', "/api/admin/users/{$target['id']}");

        $result = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$target['id']}/blocked", ['blocked' => false]);

        self::assertSame(400, $result['status']);
        self::assertSame('This account has already been deleted.', $result['json']['error']);
    }

    public function testSetAdminRejectsAnAlreadyDeletedUser(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('admin-set-admin-deleted'), admin: true);
        $target = $this->activateUser($this->secondClient(), $this->uniqueEmail('admin-set-admin-deleted-target'));
        $this->jsonRequest($client, 'PUT', "/api/admin/users/{$target['id']}/blocked", ['blocked' => true]);
        $this->jsonRequest($client, 'DELETE', "/api/admin/users/{$target['id']}");

        $result = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$target['id']}/admin", ['isAdmin' => true]);

        self::assertSame(400, $result['status']);
        self::assertSame('This account has already been deleted.', $result['json']['error']);
    }

    public function testDeleteUserRequires401WhenNotAuthenticated(): void
    {
        $client = static::createClient();

        $result = $this->jsonRequest($client, 'DELETE', '/api/admin/users/00000000-0000-0000-0000-000000000000');

        self::assertSame(401, $result['status']);
    }

    public function testDeleteUserRequires403ForANonAdmin(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('admin-delete-non-admin'));

        $result = $this->jsonRequest($client, 'DELETE', '/api/admin/users/00000000-0000-0000-0000-000000000000');

        self::assertSame(403, $result['status']);
    }

    public function testDeleteUserRejectsDeletingYourself(): void
    {
        $client = static::createClient();
        $admin = $this->activateUser($client, $this->uniqueEmail('admin-delete-self'), admin: true);

        $result = $this->jsonRequest($client, 'DELETE', "/api/admin/users/{$admin['id']}");

        self::assertSame(400, $result['status']);
        self::assertSame('You cannot delete your own account.', $result['json']['error']);
    }

    public function testDeleteUserRejectsAUserThatIsNotYetBlocked(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('admin-delete-not-blocked'), admin: true);
        $target = $this->activateUser($this->secondClient(), $this->uniqueEmail('admin-delete-not-blocked-target'));

        $result = $this->jsonRequest($client, 'DELETE', "/api/admin/users/{$target['id']}");

        self::assertSame(400, $result['status']);
        self::assertSame('Block this user before deleting their account.', $result['json']['error']);
    }

    public function testDeleteUserSucceedsForABlockedUser(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('admin-deleter'), admin: true);
        $target = $this->activateUser($this->secondClient(), $this->uniqueEmail('admin-delete-target'));

        $block = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$target['id']}/blocked", ['blocked' => true]);
        self::assertSame(200, $block['status']);

        $result = $this->jsonRequest($client, 'DELETE', "/api/admin/users/{$target['id']}");

        self::assertSame(200, $result['status']);
        self::assertSame($target['id'], $result['json']['id']);
        self::assertNotNull($result['json']['deletedAt']);
        // The response reflects the post-anonymization state, not the stale pre-deletion
        // values, so the admin panel can show the real result in place.
        self::assertSame(sprintf('deleted-%s@deleted.invalid', $target['id']), $result['json']['email']);
        self::assertSame('', $result['json']['displayName']);

        $listing = $this->jsonRequest($client, 'GET', '/api/admin/users');
        $row = current(array_filter($listing['json'], fn (array $u) => $u['id'] === $target['id']));
        self::assertNotNull($row['deletedAt'], 'the deletion must be reflected in the admin listing too');
        self::assertTrue($row['isBlocked']);
    }

    public function testDeleteUserRejectsAnAlreadyDeletedUser(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('admin-delete-twice'), admin: true);
        $target = $this->activateUser($this->secondClient(), $this->uniqueEmail('admin-delete-twice-target'));

        $this->jsonRequest($client, 'PUT', "/api/admin/users/{$target['id']}/blocked", ['blocked' => true]);
        $first = $this->jsonRequest($client, 'DELETE', "/api/admin/users/{$target['id']}");
        self::assertSame(200, $first['status']);

        $second = $this->jsonRequest($client, 'DELETE', "/api/admin/users/{$target['id']}");

        self::assertSame(400, $second['status']);
        self::assertSame('This account has already been deleted.', $second['json']['error']);
    }

    public function testDeleteUserReturns404ForAnUnknownUser(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('admin-delete-unknown'), admin: true);

        $result = $this->jsonRequest($client, 'DELETE', '/api/admin/users/00000000-0000-0000-0000-000000000000');

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
