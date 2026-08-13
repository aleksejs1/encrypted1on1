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

        self::assertNotContains($email, $this->fetchAllUserEmails($client));
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
