<?php

namespace App\Repository;

use App\Entity\ActivationToken;
use App\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActivationToken>
 */
class ActivationTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivationToken::class);
    }

    /** Tokens still redeemable in `$company`: what SeatLimitChecker counts as pending invites. */
    public function countUsable(Company $company, \DateTimeImmutable $now): int
    {
        return (int) self::usable($this->forCompany($company), $now)
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Whether a token to `$email` in `$company` (any company when null) is still
     * redeemable: an expired link to that address then reads as reissued (GitHub issue
     * #169).
     */
    public function hasUsableFor(string $email, ?Company $company, \DateTimeImmutable $now): bool
    {
        $queryBuilder = null !== $company ? $this->forCompany($company) : $this->createQueryBuilder('t');

        return (int) self::usable($queryBuilder, $now)
            ->select('COUNT(t.id)')
            ->andWhere('t.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Which of `$emails` have a redeemable token in `$company`, for the admin invite
     * list (InviteRenewal::resendableAmong()).
     *
     * @param list<string> $emails
     *
     * @return list<string>
     */
    public function emailsWithUsableToken(Company $company, array $emails, \DateTimeImmutable $now): array
    {
        if ([] === $emails) {
            return [];
        }

        /** @var list<string> $found */
        $found = self::usable($this->forCompany($company), $now)
            ->select('DISTINCT t.email')
            ->andWhere('t.email IN (:emails)')
            ->setParameter('emails', $emails)
            ->getQuery()
            ->getSingleColumnResult();

        return $found;
    }

    /**
     * `$email`'s tokens in `$company` that can't be redeemed any more (used or expired),
     * the complement of usable(): what AccountDeleter removes with the account.
     *
     * @return list<ActivationToken>
     */
    public function findSpentFor(string $email, Company $company, \DateTimeImmutable $now): array
    {
        /** @var list<ActivationToken> $tokens */
        $tokens = $this->forCompany($company)
            ->andWhere('t.email = :email')
            ->andWhere('(t.usedAt IS NOT NULL OR t.expiresAt <= :now)')
            ->setParameter('email', $email)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        return $tokens;
    }

    private function forCompany(Company $company): QueryBuilder
    {
        return $this->createQueryBuilder('t')
            ->where('t.company = :company')
            ->setParameter('company', $company);
    }

    /** Unused and unexpired at `$now`, the same rule as ActivationToken::isUsable(). */
    private static function usable(QueryBuilder $queryBuilder, \DateTimeImmutable $now): QueryBuilder
    {
        return $queryBuilder
            ->andWhere('t.usedAt IS NULL')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('now', $now);
    }
}
