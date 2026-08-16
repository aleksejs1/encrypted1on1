<?php

namespace App\Tests\Functional;

use App\Entity\ActivationToken;
use App\Entity\Company;
use App\Tests\Support\ApiTestCase;

/**
 * The dedicated, permanent proof for Phase A of private/cloud-service-plan.md (not
 * tracked in git) — real companies, real HTTP requests, asserting zero cross-company
 * leakage anywhere a user can be listed, looked up, or paired with. Mirrors
 * PrivacyBlackBoxTest's own rigor and reasoning for why this deserves a dedicated
 * always-on test rather than a one-time manual check: for a product whose entire pitch
 * is that the operator can't see your data, a cross-tenant metadata leak (who else is
 * registered, who's paired with whom) undermines that pitch nearly as much as a real
 * ciphertext leak would, even though it's "only" metadata.
 *
 * Every test here calls static::createClient() first, before touching entityManager()
 * (e.g. via makeCompany()) — WebTestCase's kernel can only be booted once per test, and
 * entityManager()'s getContainer() call boots it if nothing already has (see
 * ApiTestCase::secondClient()'s own docblock for the same constraint).
 *
 * tearDown() cleans up every company this class creates (and the users/tokens attached
 * to them) — this test suite doesn't isolate row counts between tests (no per-test
 * transaction rollback, by design — see ApiTestCase's own uniqueEmail()/per-test-IP
 * conventions), and SingleCompanyProvider::get() now genuinely fails once more than one
 * Company row exists (Phase B). Leaving these companies behind would break every other
 * test file that relies on the single-default-company shortcut, purely by virtue of
 * having run after this one in the same suite invocation — a real bug hit and fixed
 * while adding Phase B, not a hypothetical one.
 */
class CompanyIsolationTest extends ApiTestCase
{
    /** @var list<string> */
    private array $createdCompanyIds = [];

    protected function tearDown(): void
    {
        if ([] !== $this->createdCompanyIds) {
            $connection = $this->entityManager()->getConnection();
            $placeholders = implode(',', array_fill(0, \count($this->createdCompanyIds), '?'));
            // FK-safe order: children (tokens, users) before the company row itself.
            $connection->executeStatement("DELETE FROM activation_tokens WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM users WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM companies WHERE id IN ({$placeholders})", $this->createdCompanyIds);
        }

        parent::tearDown();
    }

    public function testGetUsersNeverListsAnotherCompanysMembers(): void
    {
        $aClient = static::createClient();
        $companyA = $this->makeCompany('Company A');
        $companyB = $this->makeCompany('Company B');
        $userA = $this->activateUser($aClient, $this->uniqueEmail('isolation-a'), company: $companyA);
        $bClient = $this->secondClient();
        $userB = $this->activateUser($bClient, $this->uniqueEmail('isolation-b'), company: $companyB);

        $result = $this->jsonRequest($aClient, 'GET', '/api/users');

        self::assertSame(200, $result['status']);
        $ids = array_column($result['json'], 'id');
        self::assertContains($userA['id'], $ids, 'a company-A user must still see themselves');
        self::assertNotContains($userB['id'], $ids, 'a company-B user must never appear in company A\'s counterpart picker');
    }

    public function testGetUsersRequiresAuthentication(): void
    {
        // Was "read-only, unauthenticated by design" before Phase A — that stops being
        // safe the moment a second company can exist on the same database, since an
        // unauthenticated caller has no company to scope the query by. See
        // ExcludeDeletedUsersExtension's own docblock for the full reasoning.
        $client = static::createClient();

        $result = $this->jsonRequest($client, 'GET', '/api/users');

        self::assertSame(401, $result['status']);
    }

    public function testAdminUsersListOnlyIncludesTheAdminsOwnCompany(): void
    {
        $adminClient = static::createClient();
        $companyA = $this->makeCompany('Company A');
        $companyB = $this->makeCompany('Company B');
        $admin = $this->activateUser($adminClient, $this->uniqueEmail('isolation-admin'), admin: true, company: $companyA);
        $otherCompanyClient = $this->secondClient();
        $otherCompanyUser = $this->activateUser($otherCompanyClient, $this->uniqueEmail('isolation-other'), company: $companyB);

        $result = $this->jsonRequest($adminClient, 'GET', '/api/admin/users');

        self::assertSame(200, $result['status']);
        $ids = array_column($result['json'], 'id');
        self::assertContains($admin['id'], $ids);
        self::assertNotContains($otherCompanyUser['id'], $ids, 'a company-A admin must never see a company-B user in the admin panel');
    }

    public function testAdminCannotBlockAUserFromAnotherCompany(): void
    {
        $adminClient = static::createClient();
        $companyA = $this->makeCompany('Company A');
        $companyB = $this->makeCompany('Company B');
        $this->activateUser($adminClient, $this->uniqueEmail('isolation-blocker'), admin: true, company: $companyA);
        $otherCompanyClient = $this->secondClient();
        $target = $this->activateUser($otherCompanyClient, $this->uniqueEmail('isolation-target'), company: $companyB);

        $result = $this->jsonRequest($adminClient, 'PUT', "/api/admin/users/{$target['id']}/blocked", ['blocked' => true]);

        // Same 404 a genuinely nonexistent id would get — never a distinct "wrong
        // company" response, so this can't be used to fingerprint another company's ids.
        self::assertSame(404, $result['status']);
    }

    public function testAdminCannotGrantAdminToAUserFromAnotherCompany(): void
    {
        $adminClient = static::createClient();
        $companyA = $this->makeCompany('Company A');
        $companyB = $this->makeCompany('Company B');
        $this->activateUser($adminClient, $this->uniqueEmail('isolation-granter'), admin: true, company: $companyA);
        $otherCompanyClient = $this->secondClient();
        $target = $this->activateUser($otherCompanyClient, $this->uniqueEmail('isolation-grantee'), company: $companyB);

        $result = $this->jsonRequest($adminClient, 'PUT', "/api/admin/users/{$target['id']}/admin", ['isAdmin' => true]);

        self::assertSame(404, $result['status']);
    }

    public function testCannotCreateAnAnketaWithACounterpartFromAnotherCompany(): void
    {
        $employeeClient = static::createClient();
        $companyA = $this->makeCompany('Company A');
        $companyB = $this->makeCompany('Company B');
        $this->activateUser($employeeClient, $this->uniqueEmail('isolation-employee'), company: $companyA);
        $managerClient = $this->secondClient();
        $manager = $this->activateUser($managerClient, $this->uniqueEmail('isolation-manager'), company: $companyB);

        $result = $this->jsonRequest($employeeClient, 'POST', '/api/anketas', [
            'counterpartId' => $manager['id'],
            'myRole' => 'employee',
            'meetingDate' => (new \DateTimeImmutable('+1 day'))->format(\DateTimeImmutable::ATOM),
            'mySealedKey' => str_repeat('e', 44),
            'counterpartSealedKey' => str_repeat('m', 44),
            'periodicityDays' => 30,
        ]);

        // Same status/message a genuinely nonexistent counterpart id would get — see
        // AnketaController::create()'s own comment for why this doesn't distinguish
        // "wrong company" from "doesn't exist".
        self::assertSame(404, $result['status']);
    }

    public function testInvitedUserJoinsTheInvitersCompanyNotTheSingleDefaultOne(): void
    {
        $inviterClient = static::createClient();
        $companyA = $this->makeCompany('Company A');
        $this->activateUser($inviterClient, $this->uniqueEmail('isolation-inviter'), company: $companyA);

        $inviteeEmail = $this->uniqueEmail('isolation-invitee');
        $result = $this->jsonRequest($inviterClient, 'POST', '/api/invites', ['email' => $inviteeEmail]);
        self::assertSame(201, $result['status']);

        $token = $this->entityManager()->getRepository(ActivationToken::class)->findOneBy(['email' => $inviteeEmail]);
        self::assertNotNull($token);
        self::assertSame($companyA->getId(), $token->getCompany()->getId(), 'an invite must carry the inviter\'s own company, not the single default one a differently-companied inviter would get');
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
