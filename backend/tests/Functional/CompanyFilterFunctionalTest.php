<?php

namespace App\Tests\Functional;

use App\Doctrine\CompanyFilter;
use App\Entity\Anketa;
use App\Entity\Company;
use App\Entity\InviteRecord;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;

/**
 * Tests machine-enforced multi-tenant isolation via CompanyFilter (GitHub issue #69).
 *
 * Verifies that when a request is authenticated, Doctrine SQLFilter automatically
 * scopes all queries for User, Anketa, and InviteRecord to that user's company,
 * and that platform-admin operations explicitly bypass this boundary.
 */
class CompanyFilterFunctionalTest extends ApiTestCase
{
    /** @var list<string> */
    private array $createdCompanyIds = [];

    protected function tearDown(): void
    {
        if ($this->entityManager()->getFilters()->isEnabled(CompanyFilter::NAME)) {
            $this->entityManager()->getFilters()->disable(CompanyFilter::NAME);
        }

        if ([] !== $this->createdCompanyIds) {
            $connection = $this->entityManager()->getConnection();
            $placeholders = implode(',', array_fill(0, \count($this->createdCompanyIds), '?'));
            $connection->executeStatement("DELETE FROM anketas WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM invite_records WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM activation_tokens WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM users WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM companies WHERE id IN ({$placeholders})", $this->createdCompanyIds);
        }

        parent::tearDown();
    }

    public function testSqlFilterScopesOrmQueriesToAuthenticatedUserCompany(): void
    {
        $clientA = static::createClient();
        $companyA = $this->makeCompany('Filter Test Company A');
        $companyB = $this->makeCompany('Filter Test Company B');

        $userA = $this->activateUser($clientA, $this->uniqueEmail('filter-user-a'), company: $companyA);
        $clientB = $this->secondClient();
        $userB = $this->activateUser($clientB, $this->uniqueEmail('filter-user-b'), company: $companyB);

        $em = $this->entityManager();
        if ($em->getFilters()->isEnabled(CompanyFilter::NAME)) {
            $em->getFilters()->disable(CompanyFilter::NAME);
        }

        // Directly seed an anketa for Company A and Company B
        $userAEntity = $em->find(User::class, $userA['id']);
        $userBEntity = $em->find(User::class, $userB['id']);
        \assert($userAEntity instanceof User && $userBEntity instanceof User);

        $anketaA = new Anketa($userAEntity, $userAEntity, new \DateTimeImmutable('+1 day'), 'x', 'x', 30);
        $anketaB = new Anketa($userBEntity, $userBEntity, new \DateTimeImmutable('+2 days'), 'y', 'y', 30);
        $em->persist($anketaA);
        $em->persist($anketaB);

        $inviteA = new InviteRecord('inv-a-'.bin2hex(random_bytes(4)), 'a@example.com', $userAEntity->getCompany(), $userAEntity, new \DateTimeImmutable('+1 day'));
        $inviteB = new InviteRecord('inv-b-'.bin2hex(random_bytes(4)), 'b@example.com', $userBEntity->getCompany(), $userBEntity, new \DateTimeImmutable('+1 day'));
        $em->persist($inviteA);
        $em->persist($inviteB);
        $em->flush();

        // Make an authenticated request as User A to trigger CompanyFilterListener
        $response = $this->jsonRequest($clientA, 'GET', '/api/me');
        self::assertSame(200, $response['status']);

        // Directly query the EntityManager that serviced request A
        $requestContainerEm = $clientA->getContainer()->get('doctrine.orm.entity_manager');
        \assert($requestContainerEm instanceof \Doctrine\ORM\EntityManagerInterface);

        self::assertTrue($requestContainerEm->getFilters()->isEnabled(CompanyFilter::NAME));

        // find() on another company's user must return null via SQL filter
        $foundUserB = $requestContainerEm->find(User::class, $userB['id']);
        self::assertNull($foundUserB, 'SQLFilter must prevent find() on cross-tenant user');

        // find() on another company's anketa must return null via SQL filter
        $foundAnketaB = $requestContainerEm->find(Anketa::class, $anketaB->getId());
        self::assertNull($foundAnketaB, 'SQLFilter must prevent find() on cross-tenant anketa');

        // find() on own anketa must succeed
        $foundAnketaA = $requestContainerEm->find(Anketa::class, $anketaA->getId());
        self::assertNotNull($foundAnketaA);
        self::assertSame($anketaA->getId(), $foundAnketaA->getId());

        // Unscoped DQL on Anketa must only return Company A anketas
        $anketas = $requestContainerEm->createQuery('SELECT a FROM App\Entity\Anketa a')->getResult();
        $anketaIds = array_map(static fn (Anketa $a) => $a->getId(), $anketas);
        self::assertContains($anketaA->getId(), $anketaIds);
        self::assertNotContains($anketaB->getId(), $anketaIds);

        // Unscoped DQL on InviteRecord must only return Company A invites
        $invites = $requestContainerEm->createQuery('SELECT i FROM App\Entity\InviteRecord i')->getResult();
        $inviteIds = array_map(static fn (InviteRecord $i) => $i->getId(), $invites);
        self::assertContains($inviteA->getId(), $inviteIds);
        self::assertNotContains($inviteB->getId(), $inviteIds);

        // HTTP GET on cross-tenant anketa must return 404
        $crossTenantResponse = $this->jsonRequest($clientA, 'GET', '/api/anketas/'.$anketaB->getId());
        self::assertSame(404, $crossTenantResponse['status']);

        // HTTP GET on own anketa must succeed with 200
        $ownResponse = $this->jsonRequest($clientA, 'GET', '/api/anketas/'.$anketaA->getId());
        self::assertSame(200, $ownResponse['status']);
    }

    public function testPlatformAdminRequestBypassesCompanyFilter(): void
    {
        $client = static::createClient();
        $companyA = $this->makeCompany('Filter PA Co A');
        $companyB = $this->makeCompany('Filter PA Co B');

        $platformAdmin = $this->activateUser($client, $this->uniqueEmail('filter-pa'), admin: true, company: $companyA);

        if ($this->entityManager()->getFilters()->isEnabled(CompanyFilter::NAME)) {
            $this->entityManager()->getFilters()->disable(CompanyFilter::NAME);
        }

        $paEntity = $this->entityManager()->find(User::class, $platformAdmin['id']);
        \assert($paEntity instanceof User);
        $paEntity->setPlatformAdmin(true);
        $this->entityManager()->flush();

        $clientB = $this->secondClient();
        $userB = $this->activateUser($clientB, $this->uniqueEmail('filter-pa-b'), company: $companyB);

        // Platform admin calls cross-tenant endpoint
        $usersResponse = $this->jsonRequest($client, 'GET', '/api/platform-admin/users');
        self::assertSame(200, $usersResponse['status']);

        $returnedUserIds = array_column($usersResponse['json'], 'id');
        self::assertContains($platformAdmin['id'], $returnedUserIds);
        self::assertContains($userB['id'], $returnedUserIds, 'Platform admin must see users across all companies');
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
