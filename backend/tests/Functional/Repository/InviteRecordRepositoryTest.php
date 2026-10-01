<?php

namespace App\Tests\Functional\Repository;

use App\Entity\InviteRecord;
use App\Repository\InviteRecordRepository;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\Uid\Uuid;

/** GitHub issue #169: the renewal-request claim behind POST .../request-renewal. */
class InviteRecordRepositoryTest extends ApiTestCase
{
    public function testClaimSucceedsOnceWithinTheCooldown(): void
    {
        static::createClient();
        $id = $this->makeInviteRecord();
        $now = new \DateTimeImmutable();

        self::assertTrue($this->repository()->claimRenewalRequest($id, $now));
        self::assertFalse($this->repository()->claimRenewalRequest($id, $now->modify('+1 minute')));

        self::assertEquals($now->format('Y-m-d H:i:s'), $this->renewalRequestedAt($id)?->format('Y-m-d H:i:s'));
    }

    public function testClaimSucceedsAgainOnceTheCooldownHasPassed(): void
    {
        static::createClient();
        $id = $this->makeInviteRecord();
        $first = new \DateTimeImmutable('-2 days');
        $this->repository()->claimRenewalRequest($id, $first);

        $atCooldownEnd = $first->modify(sprintf('+%d hours', InviteRecord::RENEWAL_COOLDOWN_HOURS));
        self::assertTrue($this->repository()->claimRenewalRequest($id, $atCooldownEnd));
        self::assertSame($atCooldownEnd->format('Y-m-d H:i:s'), $this->renewalRequestedAt($id)?->format('Y-m-d H:i:s'));
    }

    public function testClaimLeavesOtherRecordsUntouched(): void
    {
        static::createClient();
        $id = $this->makeInviteRecord();
        $otherId = $this->makeInviteRecord();

        $this->repository()->claimRenewalRequest($id, new \DateTimeImmutable());

        self::assertNull($this->renewalRequestedAt($otherId));
    }

    public function testClaimOfAnUnknownIdIsFalse(): void
    {
        static::createClient();

        self::assertFalse($this->repository()->claimRenewalRequest(Uuid::v7()->toRfc4122(), new \DateTimeImmutable()));
    }

    public function testReleaseRestoresThePreviousValue(): void
    {
        static::createClient();
        $id = $this->makeInviteRecord();
        $previous = new \DateTimeImmutable('-3 days');
        $this->repository()->claimRenewalRequest($id, $previous);
        $claimedAt = new \DateTimeImmutable();
        $this->repository()->claimRenewalRequest($id, $claimedAt);

        $this->repository()->releaseRenewalRequest($id, $claimedAt, $previous);

        self::assertSame($previous->format('Y-m-d H:i:s'), $this->renewalRequestedAt($id)?->format('Y-m-d H:i:s'));
    }

    /** A later claim than the one being released is someone else's, and stays. */
    public function testReleaseOfAClaimThatIsNoLongerCurrentChangesNothing(): void
    {
        static::createClient();
        $id = $this->makeInviteRecord();
        $current = new \DateTimeImmutable();
        $this->repository()->claimRenewalRequest($id, $current);

        $this->repository()->releaseRenewalRequest($id, $current->modify('-2 days'), null);

        self::assertSame($current->format('Y-m-d H:i:s'), $this->renewalRequestedAt($id)?->format('Y-m-d H:i:s'));
    }

    private function repository(): InviteRecordRepository
    {
        return $this->entityManager()->getRepository(InviteRecord::class);
    }

    private function makeInviteRecord(): string
    {
        $inviteRecord = new InviteRecord(Uuid::v7()->toRfc4122(), $this->uniqueEmail('repo-renewal'), $this->singleCompanyProvider()->get(), null, new \DateTimeImmutable('-1 hour'));
        $this->entityManager()->persist($inviteRecord);
        $this->entityManager()->flush();

        return $inviteRecord->getId();
    }

    private function renewalRequestedAt(string $id): ?\DateTimeImmutable
    {
        $this->entityManager()->clear();

        return $this->entityManager()->find(InviteRecord::class, $id)?->getRenewalRequestedAt();
    }
}
