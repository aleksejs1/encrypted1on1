<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The session mechanism named in the spec: a plain Symfony session (cookie
 * config lives in config/packages/framework.php), not JWT — this is the one
 * place that reads/writes it, so login and activation-complete share
 * identical session-creation behavior.
 */
class AuthSession
{
    private const SESSION_KEY = 'user_id';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function logIn(Request $request, User $user): void
    {
        $session = $request->getSession();
        $session->set(self::SESSION_KEY, $user->getId());
        // Regenerate the session id on privilege change to prevent session fixation.
        $session->migrate();
    }

    public function getCurrentUser(Request $request): ?User
    {
        $id = $request->getSession()->get(self::SESSION_KEY);
        if (null === $id) {
            return null;
        }

        return $this->entityManager->find(User::class, $id);
    }

    public function logOut(Request $request): void
    {
        $request->getSession()->invalidate();
    }
}
