<?php

namespace App\Billing;

use App\Entity\Company;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Phase D of private/cloud-service-plan.md (not tracked in git) — the one piece of real
 * billing enforcement that doesn't need Stripe at all, just a number already on Company
 * (Company::$seatLimit). Two real call sites (InviteController, SignupController — every
 * place a company can gain a member after its first), the same "two call sites clears
 * this project's own extraction bar" reasoning RateLimitResponse/SingleCompanyProvider
 * already established.
 *
 * Counts deletedAt IS NULL rows only — a deleted (anonymized-in-place) account no longer
 * occupies a functional seat, and counting it forever would make a company with any
 * churn eventually look permanently full. Blocked users still count: isBlocked is a
 * reversible gate, not a capacity-freeing action, and an admin/platform admin can always
 * unblock or a platform admin can raise the limit.
 */
class SeatLimitChecker
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function hasReachedLimit(Company $company): bool
    {
        $seatLimit = $company->getSeatLimit();
        if (null === $seatLimit) {
            return false;
        }

        $currentCount = $this->entityManager->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where('u.company = :company')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $currentCount >= $seatLimit;
    }
}
