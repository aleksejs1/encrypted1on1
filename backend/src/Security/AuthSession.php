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

        $user = $this->entityManager->find(User::class, $id);
        if (null === $user) {
            return null;
        }

        // AuthController::login() already refuses a blocked account or a suspended
        // company at login time — this closes the same gate for a session that was
        // already open *before* the block/suspension happened: without this, blocking
        // someone (or a platform admin suspending their company) has no effect until
        // that session naturally expires or they log out themselves, which defeats the
        // point of a reversible, supposedly-immediate gate. Every caller already treats
        // a null return as "not authenticated" (401), so this needs no separate error
        // shape; logging out here (rather than just returning null) also cleans up the
        // now-useless session instead of leaving it to expire on its own.
        if ($user->isBlocked() || $user->getCompany()->isSuspended()) {
            $this->logOut($request);

            return null;
        }

        return $user;
    }

    public function logOut(Request $request): void
    {
        $request->getSession()->invalidate();
    }

    /**
     * Releases the session's file lock (native file-session `flock`, held from
     * session_start() until the session is written) right after a read, instead of
     * holding it for the rest of a slow request — otherwise every other request from
     * the same browser (another tab, a parallel /api/anketas fetch, a debounced draft
     * autosave) queues up behind whichever one is slowest.
     *
     * Only call this from a request that is done touching the session — calling
     * logIn()/logOut() afterward in the same request would silently no-op
     * (NativeSessionStorage::regenerate() returns false once the session is already
     * closed, instead of throwing), so this is opt-in per call site, not automatic
     * inside getCurrentUser().
     */
    public function closeForReading(Request $request): void
    {
        $session = $request->getSession();
        if ($session->isStarted()) {
            $session->save();
        }
    }
}
