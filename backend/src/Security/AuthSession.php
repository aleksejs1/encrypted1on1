<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
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
    private const LAST_ACTIVE_KEY = 'last_active_at';

    /**
     * A logged-in session unused for longer than this is logged out on its next
     * request (GitHub issue #194) — 12 hours covers a working day. Enforced here
     * rather than left to session.gc_maxlifetime, which is only a lower bound: PHP's
     * session GC is probabilistic, so an idle session file can outlive it by a lot.
     */
    public const IDLE_TIMEOUT_SECONDS = 12 * 60 * 60;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function logIn(Request $request, User $user): void
    {
        $session = $request->getSession();
        $session->set(self::SESSION_KEY, $user->getId());
        $session->set(self::LAST_ACTIVE_KEY, $this->clock->now()->getTimestamp());
        // Regenerate the session id on privilege change to prevent session fixation.
        $session->migrate();

        $filter = $this->entityManager->getFilters()->enable(\App\Doctrine\CompanyFilter::NAME);
        $filter->setParameter(\App\Doctrine\CompanyFilter::PARAMETER_NAME, $user->getCompany()->getId());
    }

    public function getCurrentUser(Request $request): ?User
    {
        $session = $request->getSession();
        $id = $session->get(self::SESSION_KEY);
        if (null === $id) {
            return null;
        }

        // A logged-in session with no timestamp at all counts as idle too, so a
        // session can never skip the timeout by not having one.
        $now = $this->clock->now()->getTimestamp();
        $lastActiveAt = $session->get(self::LAST_ACTIVE_KEY);
        if (!\is_int($lastActiveAt) || $now - $lastActiveAt > self::IDLE_TIMEOUT_SECONDS) {
            // Only the login is dropped, not the whole session as in logOut(): that
            // would also destroy the CSRF secret, and this runs (CompanyFilterListener)
            // before the CSRF check — a tab coming back after a night would get a 403
            // "invalid CSRF token" on its first Save instead of the 401 that sends it
            // to the login screen, and its cached token would then fail the login too.
            $session->remove(self::SESSION_KEY);
            $session->remove(self::LAST_ACTIVE_KEY);
            $this->disableCompanyFilter();

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

        // Every authenticated request counts as activity, the anketa page's 4s
        // live-update poll included — a visible open page keeps its session alive
        // (the poll pauses while the tab is hidden).
        $session->set(self::LAST_ACTIVE_KEY, $now);

        return $user;
    }

    public function logOut(Request $request): void
    {
        $request->getSession()->invalidate();
        $this->disableCompanyFilter();
    }

    private function disableCompanyFilter(): void
    {
        if ($this->entityManager->getFilters()->isEnabled(\App\Doctrine\CompanyFilter::NAME)) {
            $this->entityManager->getFilters()->disable(\App\Doctrine\CompanyFilter::NAME);
        }
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
