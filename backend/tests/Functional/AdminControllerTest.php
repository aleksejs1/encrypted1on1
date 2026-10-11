<?php

namespace App\Tests\Functional;

use App\Entity\Company;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

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

    /**
     * An admin plus two more users of the admin's company, each activated through the
     * one spare client (only the admin's session is used afterwards).
     *
     * @return array{0: KernelBrowser, 1: array{id: string, email: string, isAdmin: bool}, 2: array{id: string, email: string, isAdmin: bool}}
     */
    private function adminAndTwoUsers(string $label): array
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail("{$label}-admin"), admin: true);
        $spare = $this->secondClient();

        return [
            $client,
            $this->activateUser($spare, $this->uniqueEmail("{$label}-a")),
            $this->activateUser($spare, $this->uniqueEmail("{$label}-b")),
        ];
    }

    public function testSetManagerSetsAndClearsTheManager(): void
    {
        [$client, $anna, $boris] = $this->adminAndTwoUsers('manager-set');

        $set = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$anna['id']}/manager", ['managerId' => $boris['id']]);
        self::assertSame(200, $set['status']);
        self::assertSame(['id' => $anna['id'], 'managerId' => $boris['id']], $set['json']);

        $rows = array_column($this->jsonRequest($client, 'GET', '/api/admin/users')['json'], 'managerId', 'id');
        self::assertSame($boris['id'], $rows[$anna['id']]);
        self::assertNull($rows[$boris['id']]);

        $clear = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$anna['id']}/manager", ['managerId' => null]);
        self::assertSame(200, $clear['status']);
        self::assertNull($clear['json']['managerId']);

        $rows = array_column($this->jsonRequest($client, 'GET', '/api/admin/users')['json'], 'managerId', 'id');
        self::assertNull($rows[$anna['id']]);
    }

    public function testSetManagerRequires403ForANonAdmin(): void
    {
        $client = static::createClient();
        $me = $this->activateUser($client, $this->uniqueEmail('manager-non-admin'));
        $other = $this->activateUser($this->secondClient(), $this->uniqueEmail('manager-non-admin-other'));

        // Naming yourself someone's manager, or picking your own, is exactly what must not work.
        $claim = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$other['id']}/manager", ['managerId' => $me['id']]);
        $pick = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$me['id']}/manager", ['managerId' => $other['id']]);

        self::assertSame(403, $claim['status']);
        self::assertSame(403, $pick['status']);
        $entity = $this->entityManager()->find(User::class, $other['id']);
        \assert($entity instanceof User);
        self::assertNull($entity->getManager());
    }

    public function testSetManagerRequiresTheManagerIdField(): void
    {
        [$client, $anna, $boris] = $this->adminAndTwoUsers('manager-missing');
        $this->jsonRequest($client, 'PUT', "/api/admin/users/{$anna['id']}/manager", ['managerId' => $boris['id']]);

        // A body without the field must not read as "clear the manager".
        $missing = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$anna['id']}/manager", []);
        $wrongType = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$anna['id']}/manager", ['managerId' => 5]);

        self::assertSame(400, $missing['status']);
        self::assertSame('Missing or invalid "managerId".', $missing['json']['violations'][0]['message']);
        self::assertSame(400, $wrongType['status']);
        $rows = array_column($this->jsonRequest($client, 'GET', '/api/admin/users')['json'], 'managerId', 'id');
        self::assertSame($boris['id'], $rows[$anna['id']]);
    }

    public function testSetManagerRejectsAManagerFromAnotherCompanyAsNotFound(): void
    {
        [$client, $anna] = $this->adminAndTwoUsers('manager-cross');
        $outsider = $this->activateUser($this->secondClient(), $this->uniqueEmail('manager-outsider'), company: $this->makeCompany('Manager Other Co'));

        $crossManager = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$anna['id']}/manager", ['managerId' => $outsider['id']]);
        $crossTarget = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$outsider['id']}/manager", ['managerId' => $anna['id']]);
        $unknown = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$anna['id']}/manager", ['managerId' => 'no-such-user']);

        // Indistinguishable from an id that doesn't exist.
        self::assertSame(404, $crossManager['status']);
        self::assertSame(404, $crossTarget['status']);
        self::assertSame(404, $unknown['status']);
        self::assertSame($unknown['json'], $crossManager['json']);
    }

    public function testSetManagerRejectsACycleWithTheTranslatedMessage(): void
    {
        [$client, $anna, $boris] = $this->adminAndTwoUsers('manager-cycle');
        $this->jsonRequest($client, 'PUT', "/api/admin/users/{$anna['id']}/manager", ['managerId' => $boris['id']]);

        $english = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$boris['id']}/manager", ['managerId' => $anna['id']]);
        $russian = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$boris['id']}/manager", ['managerId' => $anna['id']], ['HTTP_X_LOCALE' => 'ru']);

        self::assertSame(400, $english['status']);
        self::assertStringStartsWith('This would make a loop', $english['json']['error']);
        self::assertSame(400, $russian['status']);
        self::assertStringStartsWith('Получился бы замкнутый круг', $russian['json']['error']);
        $rows = array_column($this->jsonRequest($client, 'GET', '/api/admin/users')['json'], 'managerId', 'id');
        self::assertNull($rows[$boris['id']]);
    }

    public function testSetManagerRejectsThePersonThemselves(): void
    {
        [$client, $anna] = $this->adminAndTwoUsers('manager-self');

        $result = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$anna['id']}/manager", ['managerId' => $anna['id']]);

        self::assertSame(400, $result['status']);
        self::assertSame("You can't make a person their own manager.", $result['json']['error']);
    }

    public function testSetManagerRejectsABlockedManagerButBlockingKeepsExistingReports(): void
    {
        [$client, $anna, $boris] = $this->adminAndTwoUsers('manager-blocked');
        $this->jsonRequest($client, 'PUT', "/api/admin/users/{$anna['id']}/manager", ['managerId' => $boris['id']]);
        $this->jsonRequest($client, 'PUT', "/api/admin/users/{$boris['id']}/blocked", ['blocked' => true]);

        // Blocking is reversible, so it clears nothing.
        $rows = array_column($this->jsonRequest($client, 'GET', '/api/admin/users')['json'], 'managerId', 'id');
        self::assertSame($boris['id'], $rows[$anna['id']]);

        $admin = $this->jsonRequest($client, 'GET', '/api/me')['json'];
        $result = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$admin['id']}/manager", ['managerId' => $boris['id']]);
        self::assertSame(400, $result['status']);
        self::assertStringStartsWith("This person can't be set as a manager", $result['json']['error']);
    }

    /** A link an assignment racing the deletion left on a deleted account: clearable, not settable. */
    public function testSetManagerOnADeletedAccountOnlyClears(): void
    {
        [$client, $anna, $boris] = $this->adminAndTwoUsers('manager-of-deleted');
        $em = $this->entityManager();
        $annaEntity = $em->find(User::class, $anna['id']);
        $borisEntity = $em->find(User::class, $boris['id']);
        \assert($annaEntity instanceof User && $borisEntity instanceof User);
        $annaEntity->delete();
        $annaEntity->setManager($borisEntity);
        $em->flush();

        $admin = $this->jsonRequest($client, 'GET', '/api/me')['json'];
        $set = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$anna['id']}/manager", ['managerId' => $admin['id']]);
        self::assertSame(400, $set['status']);
        self::assertSame('This account has already been deleted.', $set['json']['error']);

        $clear = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$anna['id']}/manager", ['managerId' => null]);
        self::assertSame(200, $clear['status']);
        self::assertNull($clear['json']['managerId']);
    }

    public function testDeletingAManagerThroughTheAdminPanelClearsTheirReports(): void
    {
        [$client, $anna, $boris] = $this->adminAndTwoUsers('manager-deleted');
        $this->jsonRequest($client, 'PUT', "/api/admin/users/{$anna['id']}/manager", ['managerId' => $boris['id']]);
        $this->jsonRequest($client, 'PUT', "/api/admin/users/{$boris['id']}/blocked", ['blocked' => true]);

        $delete = $this->jsonRequest($client, 'DELETE', "/api/admin/users/{$boris['id']}");

        self::assertSame(200, $delete['status']);
        $rows = array_column($this->jsonRequest($client, 'GET', '/api/admin/users')['json'], 'managerId', 'id');
        self::assertNull($rows[$anna['id']]);
    }

    /**
     * @param list<array{0: string, 1: ?string}> $rows each an employee email and a manager email
     *
     * @return array{status: int, json: mixed}
     */
    private function import(KernelBrowser $client, array $rows, bool $dryRun): array
    {
        return $this->jsonRequest($client, 'POST', '/api/admin/org-structure/import', [
            'dryRun' => $dryRun,
            'assignments' => array_map(static fn (array $row): array => ['employeeEmail' => $row[0], 'managerEmail' => $row[1]], $rows),
        ]);
    }

    /** @return array<string, ?string> manager id by user id, as the admin list has it */
    private function managerIds(KernelBrowser $client): array
    {
        return array_column($this->jsonRequest($client, 'GET', '/api/admin/users')['json'], 'managerId', 'id');
    }

    public function testImportDryRunAnswersWhatWouldHappenAndChangesNothing(): void
    {
        [$client, $anna, $boris] = $this->adminAndTwoUsers('import-dry');

        $result = $this->import($client, [
            [strtoupper($anna['email']), $boris['email']],
            ['nobody-'.$anna['email'], $boris['email']],
        ], dryRun: true);

        self::assertSame(200, $result['status']);
        self::assertSame([
            'dryRun' => true,
            'applied' => false,
            'blocked' => false,
            'counts' => ['rows' => 2, 'changes' => 1, 'unchanged' => 0, 'warnings' => 1, 'errors' => 0, 'blocking' => 0],
            'problems' => [['row' => 1, 'severity' => 'warning', 'reason' => 'employee_not_found']],
        ], $result['json']);
        self::assertNull($this->managerIds($client)[$anna['id']]);
    }

    public function testImportAppliesExactlyTheValidRows(): void
    {
        [$client, $anna, $boris] = $this->adminAndTwoUsers('import-apply');
        $admin = $this->jsonRequest($client, 'GET', '/api/me')['json'];

        $result = $this->import($client, [
            [$anna['email'], $boris['email']],
            [$boris['email'], $boris['email']],
            [$admin['email'], 'ghost-'.$boris['email']],
        ], dryRun: false);

        self::assertSame(200, $result['status']);
        self::assertTrue($result['json']['applied']);
        self::assertSame(['rows' => 3, 'changes' => 1, 'unchanged' => 0, 'warnings' => 1, 'errors' => 1, 'blocking' => 0], $result['json']['counts']);
        self::assertSame([
            ['row' => 1, 'severity' => 'error', 'reason' => 'own_manager'],
            ['row' => 2, 'severity' => 'warning', 'reason' => 'manager_not_found'],
        ], $result['json']['problems']);
        $managers = $this->managerIds($client);
        self::assertSame($boris['id'], $managers[$anna['id']]);
        self::assertNull($managers[$boris['id']]);
        self::assertNull($managers[$admin['id']]);

        // The same file again: nothing left to change, and a null clears.
        $again = $this->import($client, [[$anna['email'], $boris['email']]], dryRun: false);
        self::assertSame(['rows' => 1, 'changes' => 0, 'unchanged' => 1, 'warnings' => 0, 'errors' => 0, 'blocking' => 0], $again['json']['counts']);
        $this->import($client, [[$anna['email'], null]], dryRun: false);
        self::assertNull($this->managerIds($client)[$anna['id']]);
    }

    public function testImportWithACycleAppliesNothingAndAnswers400WithTheSameBody(): void
    {
        [$client, $anna, $boris] = $this->adminAndTwoUsers('import-cycle');
        $admin = $this->jsonRequest($client, 'GET', '/api/me')['json'];
        // Anna already reports to Boris; Boris under Anna closes the loop through a stored link.
        $this->jsonRequest($client, 'PUT', "/api/admin/users/{$anna['id']}/manager", ['managerId' => $boris['id']]);
        $rows = [[$boris['email'], $anna['email']], [$admin['email'], $boris['email']]];

        $dry = $this->import($client, $rows, dryRun: true);
        $real = $this->import($client, $rows, dryRun: false);

        foreach ([$dry, $real] as $result) {
            self::assertSame(400, $result['status']);
            self::assertTrue($result['json']['blocked']);
            self::assertFalse($result['json']['applied']);
            self::assertSame([['row' => 0, 'severity' => 'blocking', 'reason' => 'cycle']], $result['json']['problems']);
            self::assertStringStartsWith('These rows would make a loop', $result['json']['error']);
        }
        $managers = $this->managerIds($client);
        self::assertNull($managers[$boris['id']]);
        // The valid row was not applied either.
        self::assertNull($managers[$admin['id']]);
    }

    public function testImportRequires403ForANonAdminAnd401LoggedOut(): void
    {
        $client = static::createClient();
        self::assertSame(401, $this->import($client, [], dryRun: true)['status']);

        $me = $this->activateUser($client, $this->uniqueEmail('import-non-admin'));
        $other = $this->activateUser($this->secondClient(), $this->uniqueEmail('import-non-admin-other'));

        $result = $this->import($client, [[$other['email'], $me['email']]], dryRun: false);

        self::assertSame(403, $result['status']);
        $entity = $this->entityManager()->find(User::class, $other['id']);
        \assert($entity instanceof User);
        self::assertNull($entity->getManager());
    }

    public function testImportTreatsAnotherCompanysEmailsAsUnknown(): void
    {
        [$client, $anna] = $this->adminAndTwoUsers('import-cross');
        $outsider = $this->activateUser($this->secondClient(), $this->uniqueEmail('import-outsider'), company: $this->makeCompany('Import Other Co'));

        $result = $this->import($client, [[$anna['email'], $outsider['email']], [$outsider['email'], $anna['email']]], dryRun: false);

        self::assertSame(200, $result['status']);
        self::assertSame(['manager_not_found', 'employee_not_found'], array_column($result['json']['problems'], 'reason'));
        self::assertNull($this->managerIds($client)[$anna['id']]);
        // Read straight from the table: the tenant filter hides the other company's row.
        self::assertNull($this->entityManager()->getConnection()->fetchOne('SELECT manager_id FROM users WHERE id = ?', [$outsider['id']]));
    }

    public function testImportRejectsAMalformedBody(): void
    {
        [$client, $anna] = $this->adminAndTwoUsers('import-malformed');
        $post = fn (array $body): array => $this->jsonRequest($client, 'POST', '/api/admin/org-structure/import', $body);
        $row = ['employeeEmail' => $anna['email'], 'managerEmail' => null];

        // A missing or non-boolean dryRun must never read as "apply".
        self::assertSame(400, $post(['assignments' => [$row]])['status']);
        self::assertSame(400, $post(['dryRun' => 'false', 'assignments' => [$row]])['status']);
        self::assertSame(400, $post(['dryRun' => true])['status']);
        self::assertSame(400, $post(['dryRun' => true, 'assignments' => ['a' => $row]])['status']);
        self::assertSame(400, $post(['dryRun' => true, 'assignments' => [['managerEmail' => 'x@example.com']]])['status']);
        self::assertSame(400, $post(['dryRun' => true, 'assignments' => [['employeeEmail' => ' ']]])['status']);
        $badManager = $post(['dryRun' => true, 'assignments' => [$row, ['employeeEmail' => $anna['email'], 'managerEmail' => 5]]]);
        self::assertSame(400, $badManager['status']);
        self::assertSame('assignments[1]', $badManager['json']['violations'][0]['property']);
        // A missing managerEmail is not "clear the manager": a misnamed column would clear everyone's.
        self::assertSame(400, $post(['dryRun' => true, 'assignments' => [['employeeEmail' => $anna['email']]]])['status']);
        self::assertSame(400, $post(['dryRun' => true, 'assignments' => [['employeeEmail' => $anna['email'], 'manager_email' => null]]])['status']);
        self::assertSame(400, $post(['dryRun' => true, 'assignments' => [['employeeEmail' => str_repeat('a', 321), 'managerEmail' => null]]])['status']);
        self::assertSame(200, $post(['dryRun' => true, 'assignments' => [$row]])['status']);
    }

    public function testImportRejectsMoreRowsThanTheLimit(): void
    {
        [$client, $anna] = $this->adminAndTwoUsers('import-limit');
        $rows = array_fill(0, 1000, [$anna['email'], null]);

        self::assertSame(200, $this->import($client, $rows, dryRun: true)['status']);
        $over = $this->import($client, [...$rows, [$anna['email'], null]], dryRun: true);

        self::assertSame(400, $over['status']);
        self::assertSame('At most 1000 rows per import.', $over['json']['violations'][0]['message']);
    }

    public function testImportIsRateLimitedPerAdminDryRunsIncluded(): void
    {
        $client = static::createClient();
        // A company of its own: each call loads every user of the company.
        $this->activateUser($client, $this->uniqueEmail('import-rate-admin'), admin: true, company: $this->makeCompany('Import Rate Co'));
        $limit = (int) $_ENV['ORG_IMPORT_RATE_LIMIT'];
        for ($call = 0; $call < $limit; ++$call) {
            self::assertSame(200, $this->import($client, [], dryRun: 0 === $call % 2)['status'], "call {$call}");
        }

        $result = $this->import($client, [], dryRun: true);

        self::assertSame(429, $result['status']);
        self::assertSame('Too many requests. Please try again later.', $result['json']['error']);
    }

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
