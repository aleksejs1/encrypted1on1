<?php

namespace App\Billing;

use App\Entity\ActivationToken;
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
 *
 * Also counts outstanding (unused, unexpired) ActivationTokens for the company — without
 * this, headcount only grows once an invitee actually activates, so a burst of invites
 * issued back-to-back (each individually checked against the *current*, not-yet-grown
 * headcount) could land a company far past its seat limit the moment they all activate.
 * A used or expired token frees the "seat" it provisionally held, same reasoning as a
 * deleted user freeing theirs. Both counters read together, not as two separate
 * hasReachedLimit() calls, since a caller (InviteController/SignupController) wraps this
 * in the same transaction as the token it's about to issue — see those controllers' own
 * comments for why that matters.
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

        $activeUsers = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where('u.company = :company')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->getQuery()
            ->getSingleScalarResult();

        $pendingInvites = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(ActivationToken::class, 't')
            ->where('t.company = :company')
            ->andWhere('t.usedAt IS NULL')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('company', $company)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();

        return ($activeUsers + $pendingInvites) >= $seatLimit;
    }
}
