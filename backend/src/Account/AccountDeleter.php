<?php

namespace App\Account;

use App\Entity\Anketa;
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

        $user->delete();
    }
}
