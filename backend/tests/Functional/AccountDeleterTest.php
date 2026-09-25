<?php

namespace App\Tests\Functional;

use App\Account\AccountDeleter;
use App\Doctrine\CompanyFilter;
use App\Entity\Anketa;
use App\Entity\AnketaPrivateNote;
use App\Entity\Company;
use App\Entity\InviteRecord;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Functional tests for AccountDeleter as an injectable Symfony service.
 * Verifies that AccountDeleter is registered and injectable via container,
 * properly clears drafts on anketas where the user is employee or manager,
 * scrubs accepted and expired invite records within the user's company,
 * leaves pending unexpired or other-tenant invite records untouched,
 * and anonymizes the user row, persisting changes directly to the database.
 */
class AccountDeleterTest extends ApiTestCase
{
    protected function tearDown(): void
    {
        if ($this->entityManager()->getFilters()->isEnabled(CompanyFilter::NAME)) {
            $this->entityManager()->getFilters()->disable(CompanyFilter::NAME);
        }
        parent::tearDown();
    }

    private function accountDeleter(): AccountDeleter
    {
        $service = self::getContainer()->get(AccountDeleter::class);
        \assert($service instanceof AccountDeleter);

        return $service;
    }

    public function testServiceIsRegisteredInContainer(): void
    {
        static::createClient();
        self::assertInstanceOf(AccountDeleter::class, $this->accountDeleter());
    }

    public function testDeleteClearsDraftsForBothEmployeeAndManagerAnketas(): void
    {
        $client = static::createClient();
        $userAData = $this->activateUser($client, $this->uniqueEmail('deleter-user-a'));
        $userBData = $this->activateUser($this->secondClient(), $this->uniqueEmail('deleter-user-b'));

        $em = $this->entityManager();
        $userA = $em->find(User::class, $userAData['id']);
        $userB = $em->find(User::class, $userBData['id']);
        \assert($userA instanceof User && $userB instanceof User);

        // Anketa 1: userA is employee, userB is manager
        $anketa1 = new Anketa(
            employee: $userA,
            manager: $userB,
            meetingDate: new \DateTimeImmutable('+7 days'),
            employeeSealedKey: 'sealed-emp-1',
            managerSealedKey: 'sealed-mgr-1',
            periodicityDays: 14,
        );
        $anketa1->saveDraft($userA, 'emp-draft-blob');
        $anketa1->publish($userB, 'mgr-published-blob');
        $em->persist($anketa1);

        // Anketa 2: userB is employee, userA is manager
        $anketa2 = new Anketa(
            employee: $userB,
            manager: $userA,
            meetingDate: new \DateTimeImmutable('+14 days'),
            employeeSealedKey: 'sealed-emp-2',
            managerSealedKey: 'sealed-mgr-2',
            periodicityDays: 14,
        );
        $anketa2->publish($userB, 'emp-published-blob');
        $anketa2->saveDraft($userA, 'mgr-draft-blob');
        $em->persist($anketa2);

        $em->flush();

        $anketa1Id = $anketa1->getId();
        $anketa2Id = $anketa2->getId();

        // Delete userA
        $this->accountDeleter()->delete($userA);
        $em->flush();
        $em->clear();

        // Reload fresh entities from DB to verify persisted state
        $fresh1 = $em->find(Anketa::class, $anketa1Id);
        $fresh2 = $em->find(Anketa::class, $anketa2Id);
        \assert($fresh1 instanceof Anketa && $fresh2 instanceof Anketa);

        // userA's draft on anketa1 should be cleared; userB's published blob should remain
        self::assertNull($fresh1->getEmployeeBlob());
        self::assertSame('mgr-published-blob', $fresh1->getManagerBlob());

        // userA's draft on anketa2 should be cleared; userB's published blob should remain
        self::assertNull($fresh2->getManagerBlob());
        self::assertSame('emp-published-blob', $fresh2->getEmployeeBlob());
    }

    public function testDeleteScrubsAcceptedAndExpiredInviteRecordsWithinCompanyOnly(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('deleter-invites');
        $userData = $this->activateUser($client, $email);

        $em = $this->entityManager();
        $user = $em->find(User::class, $userData['id']);
        \assert($user instanceof User);
        $company = $user->getCompany();

        $otherCompany = new Company('Other Tenant Co');
        $em->persist($otherCompany);
        $em->flush();

        try {
            // 1. Accepted invite record at user's company
            $acceptedRecord = new InviteRecord(
                Uuid::v7()->toRfc4122(),
                $email,
                $company,
                null,
                new \DateTimeImmutable('+1 day'),
            );
            $acceptedRecord->markAccepted();
            $em->persist($acceptedRecord);

            // 2. Expired invite record at user's company
            $expiredRecord = new InviteRecord(
                Uuid::v7()->toRfc4122(),
                $email,
                $company,
                null,
                new \DateTimeImmutable('-1 hour'),
            );
            $em->persist($expiredRecord);

            // 3. Still-pending unexpired invite record at user's company
            $pendingRecord = new InviteRecord(
                Uuid::v7()->toRfc4122(),
                $email,
                $company,
                null,
                new \DateTimeImmutable('+1 day'),
            );
            $em->persist($pendingRecord);

            // 4. Expired invite record at other company with the same email
            $otherCompanyExpiredRecord = new InviteRecord(
                Uuid::v7()->toRfc4122(),
                $email,
                $otherCompany,
                null,
                new \DateTimeImmutable('-1 hour'),
            );
            $em->persist($otherCompanyExpiredRecord);

            $em->flush();

            $acceptedId = $acceptedRecord->getId();
            $expiredId = $expiredRecord->getId();
            $pendingId = $pendingRecord->getId();
            $otherCompanyExpiredId = $otherCompanyExpiredRecord->getId();

            // Delete user
            $this->accountDeleter()->delete($user);
            $em->flush();
            $em->clear();

            // Disable company_filter so we can inspect cross-tenant rows
            if ($em->getFilters()->isEnabled(CompanyFilter::NAME)) {
                $em->getFilters()->disable(CompanyFilter::NAME);
            }

            // Reload fresh records from DB
            $freshAccepted = $em->find(InviteRecord::class, $acceptedId);
            $freshExpired = $em->find(InviteRecord::class, $expiredId);
            $freshPending = $em->find(InviteRecord::class, $pendingId);
            $freshOther = $em->find(InviteRecord::class, $otherCompanyExpiredId);
            \assert($freshAccepted instanceof InviteRecord && $freshExpired instanceof InviteRecord);
            \assert($freshPending instanceof InviteRecord && $freshOther instanceof InviteRecord);

            // 1. Accepted invite email must be scrubbed
            self::assertStringEndsWith('@deleted.invalid', $freshAccepted->getEmail());

            // 2. Expired invite email must be scrubbed
            self::assertStringEndsWith('@deleted.invalid', $freshExpired->getEmail());

            // 3. Pending unexpired invite email must NOT be scrubbed
            self::assertSame($email, $freshPending->getEmail());

            // 4. Other company's invite email must NOT be scrubbed
            self::assertSame($email, $freshOther->getEmail());
        } finally {
            $em->getConnection()->executeStatement(
                'DELETE FROM invite_records WHERE company_id = ?',
                [$otherCompany->getId()],
            );
            $em->getConnection()->executeStatement(
                'DELETE FROM companies WHERE id = ?',
                [$otherCompany->getId()],
            );
        }
    }

    /** GitHub issue #132 §5.4: the user's own private notes go, the counterpart's stay. */
    public function testDeleteRemovesTheUsersPrivateNotesButNotTheCounterparts(): void
    {
        $client = static::createClient();
        $userAData = $this->activateUser($client, $this->uniqueEmail('deleter-notes-a'));
        $userBData = $this->activateUser($this->secondClient(), $this->uniqueEmail('deleter-notes-b'));

        $em = $this->entityManager();
        $userA = $em->find(User::class, $userAData['id']);
        $userB = $em->find(User::class, $userBData['id']);
        \assert($userA instanceof User && $userB instanceof User);

        $anketa = new Anketa(
            employee: $userA,
            manager: $userB,
            meetingDate: new \DateTimeImmutable('+7 days'),
            employeeSealedKey: 'sealed-emp',
            managerSealedKey: 'sealed-mgr',
            periodicityDays: 14,
        );
        // A second anketa with the roles swapped: every one of userA's notes must go, not
        // only those on some particular anketa.
        $swapped = new Anketa(
            employee: $userB,
            manager: $userA,
            meetingDate: new \DateTimeImmutable('+14 days'),
            employeeSealedKey: 'sealed-emp-2',
            managerSealedKey: 'sealed-mgr-2',
            periodicityDays: 14,
        );
        $em->persist($anketa);
        $em->persist($swapped);
        $em->persist(new AnketaPrivateNote($anketa, $userA, 'key-a', 'notes-a'));
        $em->persist(new AnketaPrivateNote($swapped, $userA, 'key-a2', 'notes-a2'));
        $em->persist(new AnketaPrivateNote($anketa, $userB, 'key-b', 'notes-b'));
        $em->flush();

        $this->accountDeleter()->delete($userA);
        $em->flush();
        $em->clear();

        self::assertSame(0, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM anketa_private_notes WHERE author_id = ?',
            [$userA->getId()],
        ));
        $remaining = $em->getRepository(AnketaPrivateNote::class)->findBy(['anketa' => $anketa->getId()]);
        self::assertCount(1, $remaining);
        self::assertSame($userB->getId(), $remaining[0]->getAuthor()->getId());
        self::assertSame('notes-b', $remaining[0]->getNotesBlob());
    }

    public function testDeleteAnonymizesUser(): void
    {
        $client = static::createClient();
        $userData = $this->activateUser($client, $this->uniqueEmail('deleter-anonymize'), displayName: 'Alice');

        $em = $this->entityManager();
        $user = $em->find(User::class, $userData['id']);
        \assert($user instanceof User);
        $userId = $user->getId();

        $this->accountDeleter()->delete($user);
        $em->flush();
        $em->clear();

        $freshUser = $em->find(User::class, $userId);
        \assert($freshUser instanceof User);

        self::assertTrue($freshUser->isBlocked());
        self::assertNotNull($freshUser->getDeletedAt());
        self::assertSame(sprintf('deleted-%s@deleted.invalid', $freshUser->getId()), $freshUser->getEmail());
        self::assertSame('', $freshUser->getDisplayName());
    }
}
