<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionUtils;

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

    private const REMEMBERED_UNTIL_KEY = 'remembered_until';
    private const CREDENTIAL_STAMP_KEY = 'credential_stamp';

    /**
     * How long a "Remember this browser" login lasts (GitHub issue #205), counted from
     * the login itself and never extended by activity — the session cookie gets the
     * same lifetime. session.gc_maxlifetime must stay above it.
     */
    public const REMEMBER_SECONDS = 30 * 24 * 60 * 60;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function logIn(Request $request, User $user, bool $remember = false): void
    {
        $session = $request->getSession();
        // Regenerate the session id on privilege change to prevent session fixation —
        // before the login is written into the session, not after: changing the cookie
        // lifetime makes migrate() save the session under its old id first, and that
        // id's file is kept, so a login already written would stay usable under the
        // pre-login id. (Kept, not destroyed: a request still in flight with the old
        // cookie would otherwise find an empty session and answer with a clear-cookie
        // header that can land after, and delete, the cookie this login sets. So a
        // login made on top of an earlier one, without a logout, leaves that earlier
        // login alive under its old id until its own timeout, as it always did.) The
        // lifetime is passed on every login, 0 (until the browser closes) included,
        // rather than left to the configured default: it's set with ini_set(), which
        // could otherwise carry over from an earlier remembered login in a
        // long-running worker. Started first: migrate() silently does nothing for a
        // session that isn't.
        $session->start();
        $session->migrate(false, $remember ? self::REMEMBER_SECONDS : 0);

        $now = $this->clock->now()->getTimestamp();
        $session->set(self::SESSION_KEY, $user->getId());
        $session->set(self::LAST_ACTIVE_KEY, $now);
        $session->set(self::CREDENTIAL_STAMP_KEY, self::credentialStamp($user));
        if ($remember) {
            $session->set(self::REMEMBERED_UNTIL_KEY, $now + self::REMEMBER_SECONDS);
        } else {
            $session->remove(self::REMEMBERED_UNTIL_KEY);
        }

        $filter = $this->entityManager->getFilters()->enable(\App\Doctrine\CompanyFilter::NAME);
        $filter->setParameter(\App\Doctrine\CompanyFilter::PARAMETER_NAME, $user->getCompany()->getId());
    }

    /**
     * How many seconds this request's remembered login still has, or null for an
     * ordinary login. A duration, not a date: the browser counts it on its own clock,
     * which may differ from the server's. Call it after getCurrentUser(), which is
     * what ends an expired one.
     */
    public function rememberedSecondsLeft(Request $request): ?int
    {
        $session = $request->getSession();
        $until = $session->has(self::SESSION_KEY) ? $session->get(self::REMEMBERED_UNTIL_KEY) : null;

        return \is_int($until) ? max(0, $until - $this->clock->now()->getTimestamp()) : null;
    }

    /**
     * Keeps this session logged in across the user's own password change, which ends
     * every other session of theirs (see getCurrentUser()).
     */
    public function refreshCredentialStamp(Request $request, User $user): void
    {
        $request->getSession()->set(self::CREDENTIAL_STAMP_KEY, self::credentialStamp($user));
    }

    /**
     * Identifies the password a session was opened under without putting the auth
     * verifier itself into the session file.
     */
    private static function credentialStamp(User $user): string
    {
        return hash('sha256', $user->getAuthHash());
    }

    public function getCurrentUser(Request $request): ?User
    {
        $session = $request->getSession();
        $id = $session->get(self::SESSION_KEY);
        if (null === $id) {
            return null;
        }

        $now = $this->clock->now()->getTimestamp();
        if ($this->hasExpired($session, $now)) {
            $this->dropLogin($request);

            return null;
        }

        $user = $this->entityManager->find(User::class, $id);
        if (null === $user) {
            return null;
        }

        if (!$this->openedUnderCurrentPassword($session, $user)) {
            $this->dropLogin($request);

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

    /**
     * A remembered login has a fixed end instead of an idle timeout. Otherwise a
     * logged-in session with no timestamp at all counts as idle too, so a session can
     * never skip the timeout by not having one.
     */
    private function hasExpired(SessionInterface $session, int $now): bool
    {
        $rememberedUntil = $session->get(self::REMEMBERED_UNTIL_KEY);
        if (\is_int($rememberedUntil)) {
            return $now > $rememberedUntil;
        }
        $lastActiveAt = $session->get(self::LAST_ACTIVE_KEY);

        return !\is_int($lastActiveAt) || $now - $lastActiveAt > self::IDLE_TIMEOUT_SECONDS;
    }

    /**
     * False once the password was changed or reset in another session since this one
     * logged in: a lost device's session ends as soon as its owner changes the
     * password, instead of serving ciphertext and metadata until it expires (up to 30
     * days when remembered). A session from before sessions carried a stamp gets the
     * current one, so the release that added it doesn't sign everyone out.
     */
    private function openedUnderCurrentPassword(SessionInterface $session, User $user): bool
    {
        $stamp = $session->get(self::CREDENTIAL_STAMP_KEY);
        if (null === $stamp) {
            $session->set(self::CREDENTIAL_STAMP_KEY, self::credentialStamp($user));

            return true;
        }

        return \is_string($stamp) && hash_equals(self::credentialStamp($user), $stamp);
    }

    /**
     * Ends the login but keeps the session, unlike logOut(): that would also destroy
     * the CSRF secret, and this runs (CompanyFilterListener) before the CSRF check — a
     * tab coming back after a night would get a 403 "invalid CSRF token" on its first
     * Save instead of the 401 that sends it to the login screen, and its cached token
     * would then fail the login too.
     */
    private function dropLogin(Request $request): void
    {
        $session = $request->getSession();
        $session->remove(self::SESSION_KEY);
        $session->remove(self::LAST_ACTIVE_KEY);
        $session->remove(self::REMEMBERED_UNTIL_KEY);
        $session->remove(self::CREDENTIAL_STAMP_KEY);
    }

    public function logOut(Request $request): void
    {
        $request->getSession()->invalidate();

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
        if (!$session->isStarted()) {
            return;
        }
        $session->save();

        // PHP sends the session cookie again each time a session starts. Symfony's
        // SessionListener takes that header back off the response, but only for a
        // session still open when the response is built — not one closed here. Left
        // in, it has no expiry, so it would turn the 30-day cookie of a "Remember this
        // browser" login back into one that ends with the browser. Only when the
        // browser already holds this id, so a cookie carrying a new one is never lost.
        if ($request->cookies->get($session->getName()) === $session->getId()) {
            SessionUtils::popSessionCookie($session->getName(), $session->getId());
        }
    }
}
