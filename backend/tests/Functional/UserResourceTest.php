<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * GET /api/users (the app's one API-Platform ApiResource, used for the counterpart
 * picker) had zero test coverage before ExcludeDeletedUsersExtension existed — this
 * confirms that extension actually wires up through the real ApiResource machinery,
 * not just that its own class logic is correct in isolation.
 */
class UserResourceTest extends ApiTestCase
{
    public function testListIncludesALiveUser(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('users-resource-live');
        $this->activateUser($client, $email);

        self::assertContains($email, $this->fetchAllUserEmails($client));
    }

    public function testListExcludesADeletedUser(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('users-resource-deleted');
        $user = $this->activateUser($client, $email);

        $entity = $this->entityManager()->find(User::class, $user['id']);
        \assert($entity instanceof User);
        $entity->delete();
        $this->entityManager()->flush();

        // The deleted account's own session no longer works to ask this question with —
        // User::delete() also sets isBlocked (defense-in-depth), and
        // AuthSession::getCurrentUser() now logs a blocked account out on its very next
        // request (see that method's own comment). Query as a second, still-live user.
        $viewerClient = $this->secondClient();
        $this->activateUser($viewerClient, $this->uniqueEmail('users-resource-deleted-viewer'));

        self::assertNotContains($email, $this->fetchAllUserEmails($viewerClient));
    }

    public function testListExcludesADemoUser(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('users-resource-demo');
        $user = $this->activateUser($client, $email);

        $entity = $this->entityManager()->find(User::class, $user['id']);
        \assert($entity instanceof User);
        $entity->setDemo(true);
        $this->entityManager()->flush();

        self::assertNotContains($email, $this->fetchAllUserEmails($client));
    }

    /**
     * The reporting line is for admins (GET /api/admin/users) and for the two people in
     * it. On this list, open to the whole company, it would hand anyone the whole tree.
     */
    public function testListNeverCarriesAUsersManager(): void
    {
        $client = static::createClient();
        $manager = $this->activateUser($client, $this->uniqueEmail('users-resource-manager'));
        $report = $this->activateUser($client, $this->uniqueEmail('users-resource-report'));

        $em = $this->entityManager();
        $reportEntity = $em->find(User::class, $report['id']);
        $managerEntity = $em->find(User::class, $manager['id']);
        \assert($reportEntity instanceof User && $managerEntity instanceof User);
        $reportEntity->setManager($managerEntity);
        $em->flush();

        $row = null;
        for ($page = 1; null === $row; ++$page) {
            $rows = $this->jsonRequest($client, 'GET', "/api/users?page={$page}")['json'];
            self::assertNotSame([], $rows, 'The report should be on some page of the list.');
            foreach ($rows as $candidate) {
                if ($candidate['id'] === $report['id']) {
                    $row = $candidate;
                }
            }
        }
        // The exact fields, so the link can't come back under another name (a
        // getManagerId() with a group, say) without this test being changed on purpose.
        $keys = array_keys($row);
        sort($keys);
        self::assertSame(['createdAt', 'displayName', 'email', 'id', 'publicKey'], $keys);
        self::assertStringNotContainsString($manager['id'], (string) json_encode($row));

        $single = $this->jsonRequest($client, 'GET', "/api/users/{$report['id']}");
        self::assertSame(200, $single['status']);
        self::assertStringNotContainsString($manager['id'], (string) json_encode($single['json']));
    }

    /**
     * The default 30-item page size (this app doesn't configure client-controllable
     * pagination) means a single request can't be trusted to contain any specific
     * user once the shared test DB has accumulated more than 30 rows from earlier
     * tests in the same run — walk every page instead of assuming page 1 is enough.
     *
     * @return list<string>
     */
    private function fetchAllUserEmails(KernelBrowser $client): array
    {
        $emails = [];
        for ($page = 1;; ++$page) {
            $result = $this->jsonRequest($client, 'GET', "/api/users?page={$page}");
            self::assertSame(200, $result['status']);
            /** @var array<int, array<string, mixed>> $rows */
            $rows = $result['json'];
            if ([] === $rows) {
                break;
            }
            foreach ($rows as $row) {
                $emails[] = $row['email'];
            }
        }

        return $emails;
    }
}
