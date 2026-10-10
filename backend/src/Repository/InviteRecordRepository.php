<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\InviteRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InviteRecord>
 */
class InviteRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InviteRecord::class);
    }

    /**
     * The newest invite to `$email` in `$company`: the one a renewal request is recorded
     * on (GitHub issue #169) and the one /admin/invites offers to re-send.
     */
    public function findNewestFor(string $email, Company $company): ?InviteRecord
    {
        /** @var InviteRecord|null $inviteRecord */
        $inviteRecord = self::newestFirst($this->createQueryBuilder('i'))
            ->where('i.email = :email')
            ->andWhere('i.company = :company')
            ->setMaxResults(1)
            ->setParameter('email', $email)
            ->setParameter('company', $company)
            ->getQuery()
            ->getOneOrNullResult();

        return $inviteRecord;
    }

    /**
     * The company's invite history for GET /api/admin/invites, newest first in the same
     * order as findNewestFor(), so the first row per address there is the one a renewal
     * request was recorded on. Fetch-joins invitedBy: the payload reads it on every row,
     * which would otherwise be an N+1 lazy-load per invite.
     *
     * @return list<InviteRecord>
     */
    public function findForCompanyNewestFirst(Company $company): array
    {
        /** @var list<InviteRecord> $inviteRecords */
        $inviteRecords = self::newestFirst($this->createQueryBuilder('i'))
            ->addSelect('invitedBy')
            ->leftJoin('i.invitedBy', 'invitedBy')
            ->where('i.company = :company')
            ->setParameter('company', $company)
            ->getQuery()
            ->getResult();

        return $inviteRecords;
    }

    /**
     * Newest first. Ties on createdAt, stored to the second, go to the larger id, a
     * time-ordered UUIDv7.
     */
    private static function newestFirst(QueryBuilder $queryBuilder): QueryBuilder
    {
        return $queryBuilder->orderBy('i.createdAt', 'DESC')->addOrderBy('i.id', 'DESC');
    }

    /**
     * Stamps renewalRequestedAt unless another request already did within
     * InviteRecord::RENEWAL_COOLDOWN_HOURS (GitHub issue #169). A conditional UPDATE,
     * same claim shape as AnketaRepository::claimReminder(): of two concurrent
     * requests only one gets `true`, so the inviter is notified once.
     */
    public function claimRenewalRequest(string $id, \DateTimeImmutable $now): bool
    {
        $affected = $this->getEntityManager()->createQuery(
            'UPDATE '.InviteRecord::class.' i SET i.renewalRequestedAt = :now'
            .' WHERE i.id = :id AND (i.renewalRequestedAt IS NULL OR i.renewalRequestedAt <= :cutoff)'
        )
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('cutoff', InviteRecord::renewalCooldownStart($now), Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $id)
            ->execute();

        return 1 === $affected;
    }

    /** Undoes claimRenewalRequest() when no notification could be sent, so the invitee can try again. */
    public function releaseRenewalRequest(string $id, \DateTimeImmutable $claimedAt, ?\DateTimeImmutable $previous): void
    {
        $this->getEntityManager()->createQuery(
            'UPDATE '.InviteRecord::class.' i SET i.renewalRequestedAt = :previous WHERE i.id = :id AND i.renewalRequestedAt = :claimedAt'
        )
            ->setParameter('previous', $previous, Types::DATETIME_IMMUTABLE)
            ->setParameter('claimedAt', $claimedAt, Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $id)
            ->execute();
    }
}
