<?php

namespace App\Account;

use App\Entity\Anketa;
use App\Entity\InviteRecord;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The identical "clear every unpublished draft, then anonymize" sequence needed by
 * both places an account can be deleted (AuthController::deleteAccount() — self-service
 * — and AdminController::deleteUser() — a company admin acting on a departed employee's
 * blocked account) — a real, mechanical second call site, the same
 * "two call sites clears this project's own extraction bar" reasoning
 * RateLimitResponse/SingleCompanyProvider already established. Drafts are cleared first
 * because they're never seen by anyone else (see Anketa::clearUnpublishedDraftFor()'s own
 * docblock) — "delete this account" should mean the same thing regardless of who
 * triggers it.
 */
final class AccountDeleter
{
    public static function delete(User $user, EntityManagerInterface $entityManager): void
    {
        /** @var Anketa[] $anketas */
        $anketas = $entityManager->createQueryBuilder()
            ->select('a')
            ->from(Anketa::class, 'a')
            ->where('a.employee = :user OR a.manager = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        foreach ($anketas as $anketa) {
            $anketa->clearUnpublishedDraftFor($user);
        }

        // Captured before delete() overwrites User::$email in place — InviteRecord
        // rows (this user's own invite history, as the invited recipient) are a
        // second, independent place that email would otherwise keep sitting in for
        // the rest of its retention window (see InviteRecord's own docblock).
        // invitedBy — the sender side — needs no equivalent scrub: it points at a
        // User row, which delete() already anonymizes in place there.
        //
        // Scoped to the user's own company: unlike users.email, activation_tokens/
        // invite_records carry no cross-company uniqueness constraint, so an entirely
        // unrelated, still-pending invite at a different company can share this exact
        // email address (e.g. a shared vendor address, or a coincidence) — deleting
        // this account must not reach into that other tenant's data.
        //
        // Excludes still-pending rows: nothing stops a duplicate re-invite to the same
        // address before the first is accepted (InviteController::create() only checks
        // for an existing User row, not an existing pending InviteRecord), and a
        // pending row's ActivationToken is still genuinely completable — scrubbing its
        // email here would corrupt a live, independently-actionable invite. An accepted
        // row can only ever be the one that actually produced this exact User (its
        // completion required this email to be free in the users table at that
        // moment), and an expired row can never be completed by anyone — both are safe
        // to scrub, same "delete means delete" reasoning as the rest of this method.
        $originalEmail = $user->getEmail();
        $now = new \DateTimeImmutable();

        /** @var InviteRecord[] $inviteRecords */
        $inviteRecords = $entityManager->createQueryBuilder()
            ->select('i')
            ->from(InviteRecord::class, 'i')
            ->where('i.email = :email')
            ->andWhere('i.company = :company')
            // Explicitly parenthesized for a reader's sake, not because it's load-bearing:
            // Doctrine's QueryBuilder auto-wraps any andWhere() part containing " OR "/" AND "
            // in parentheses when combining it with other parts (Expr\Composite's own
            // DDC-1237 fix, confirmed directly against this exact query — without the
            // explicit parens here it still compiles to "email AND company AND (accepted OR
            // expired)", not the "(email AND company AND accepted) OR (expired)" a bare SQL
            // reading would suggest). Kept explicit anyway so this doesn't require knowing
            // that Doctrine detail to read correctly, and testDeleteAccountDoesNotScrubAnExpiredInviteAtAnotherCompany
            // guards the actual runtime behavior regardless of which mechanism produces it.
            ->andWhere('(i.acceptedAt IS NOT NULL OR i.expiresAt <= :now)')
            ->setParameter('email', $originalEmail)
            ->setParameter('company', $user->getCompany())
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        foreach ($inviteRecords as $inviteRecord) {
            $inviteRecord->scrubEmail();
        }

        $user->delete();
    }
}
