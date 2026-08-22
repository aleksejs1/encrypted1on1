<?php

namespace App\Tests\Functional;

use App\Entity\Company;
use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Phase D of private/cloud-service-plan.md (not tracked in git) — Company::$suspendedAt,
 * checked at login, mirroring how User::$isBlocked already works at the account level.
 *
 * Same tearDown() cleanup discipline as CompanyIsolationTest/PlatformAdminControllerTest/
 * SeatLimitTest — this suite creates extra Company rows.
 */
class CompanySuspensionTest extends ApiTestCase
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

    public function testLoginSucceedsWhenTheCompanyIsNotSuspended(): void
    {
        [$client, $email] = $this->activateInFreshCompany('suspension-ok');

        $result = $this->login($client, $email);

        self::assertSame(200, $result['status']);
    }

    public function testLoginIsRejectedWhenTheCompanyIsSuspended(): void
    {
        [$client, $email, $company] = $this->activateInFreshCompany('suspension-blocked');
        // A real logout first — activation itself already leaves $client logged in
        // (ApiTestCase::activateUser()'s own documented behavior), and login() below
        // needs a clean, unauthenticated session to test against.
        $this->jsonRequest($client, 'POST', '/api/logout');

        $this->suspendCompany($company->getId());

        $result = $this->login($client, $email);

        self::assertSame(403, $result['status']);
        self::assertSame("This company's account is suspended. Contact support to resolve it.", $result['json']['error']);
    }

    public function testLoginSucceedsAgainAfterUnsuspending(): void
    {
        [$client, $email, $company] = $this->activateInFreshCompany('suspension-recovers');
        $this->jsonRequest($client, 'POST', '/api/logout');

        $this->suspendCompany($company->getId());
        $blocked = $this->login($client, $email);
        self::assertSame(403, $blocked['status']);

        $this->unsuspendCompany($company->getId());
        $result = $this->login($client, $email);

        self::assertSame(200, $result['status'], 'suspension must be reversible, same shape as isBlocked');
    }

    /**
     * Re-fetches by id rather than mutating a Company object handed in from earlier in
     * the test — the same real Doctrine-testing gotcha docs/history.md's own Phase B/C
     * entries already document: a real HTTP request through $client (activateUser()/logout()
     * above) clears the entity manager's identity map, leaving any earlier-held entity
     * object detached, so mutating and flushing it silently does nothing.
     */
    private function suspendCompany(string $companyId): void
    {
        $company = $this->entityManager()->find(Company::class, $companyId);
        self::assertNotNull($company);
        $company->suspend();
        $this->entityManager()->flush();
    }

    private function unsuspendCompany(string $companyId): void
    {
        $company = $this->entityManager()->find(Company::class, $companyId);
        self::assertNotNull($company);
        $company->unsuspend();
        $this->entityManager()->flush();
    }

    /** @return array{0: KernelBrowser, 1: string, 2: Company} */
    private function activateInFreshCompany(string $label): array
    {
        $client = static::createClient();
        $company = new Company('Suspension Co');
        $this->entityManager()->persist($company);
        $this->entityManager()->flush();
        $this->createdCompanyIds[] = $company->getId();

        $email = $this->uniqueEmail($label);
        $this->activateUser($client, $email, company: $company);

        return [$client, $email, $company];
    }

    /** @return array{status: int, json: mixed} */
    private function login(KernelBrowser $client, string $email): array
    {
        return $this->jsonRequest($client, 'POST', '/api/login', [
            'email' => $email,
            // ApiTestCase::activateUser() always uses this exact placeholder authKey —
            // see its own body for why opaque strings are fine here (the backend never
            // inspects them, only compares against what it stored at activation time).
            'authKey' => str_repeat('a', 44),
        ]);
    }
}
