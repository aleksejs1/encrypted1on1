import { apiGet, apiPost, ApiError, resetCsrfToken } from './api/client';
import type { MeResponse } from './api/types';
import {
  ensureUnlocked,
  getGeneration,
  invalidateIdentity,
  WrongPasswordError,
} from './crypto/identity.svelte';
import { clearMasterKey } from './crypto/session';

/**
 * 'unknown' until checkUnlocked() has run at least once for this tab's
 * current login; 'locked' once it has and this tab has no key of its own
 * (see UnlockTab.svelte); 'unlocked' once ensureUnlocked() has genuinely
 * succeeded. A single field, not two independent booleans, so "unlocked but
 * never checked" can't be represented — every write site sets exactly one
 * of these three values, never a combination App.svelte would have to
 * guess the meaning of.
 */
export type UnlockStatus = 'unknown' | 'locked' | 'unlocked';

export const authState = $state<{
  checked: boolean;
  authenticated: boolean;
  unlockStatus: UnlockStatus;
}>({
  checked: false,
  authenticated: false,
  unlockStatus: 'unknown',
});

/** True for the specific "this tab's session is no longer valid" signal every session-liveness check reacts to the same way (here and in UnlockTab.svelte's own /api/me fetch) — a 401 from an authenticated endpoint, not any other failure (network error, 5xx, wrong password). */
export function isSessionExpiredError(error: unknown): boolean {
  return error instanceof ApiError && error.status === 401;
}

/**
 * The one and only /api/me fetch on ordinary page load: confirms the
 * session cookie (authState.authenticated) and, from that exact same
 * response, immediately also resolves this tab's unlock state
 * (checkUnlocked(me) — see its own docblock) — rather than a separate
 * effect independently re-fetching /api/me a second time to answer what
 * the response already told this call. Safe to reuse `me` here (unlike
 * UnlockTab.svelte's submit-time call, which deliberately does not) because
 * there's no gap between the fetch and its use: no user input to wait on
 * in between, just the local AEAD unwrap immediately after.
 *
 * Snapshots getGeneration() before the fetch and bails if it's moved by the
 * time the fetch resolves: this call's own /api/me can be slow enough that
 * a same-tab markAuthenticated() (activating or resetting the password for
 * a *different* identity, from a link opened in this already-logged-in tab
 * — see markAuthenticated()'s own docblock) lands first. Without this
 * check, this stale call would still go on to call checkUnlocked() with the
 * old identity's `me` against the new identity's now-current master key —
 * unwrapping fails, but as a WrongPasswordError, not a "stale" signal, so
 * checkUnlocked() would overwrite the just-set unlockStatus back to
 * 'locked' right after a successful activation/reset.
 */
export async function checkAuth(): Promise<void> {
  const startedAt = getGeneration();
  try {
    const me = await apiGet<MeResponse>('/api/me');
    if (getGeneration() !== startedAt) return;
    authState.authenticated = true;
    authState.checked = true;
    await checkUnlocked(me);
  } catch (error) {
    authState.checked = true;
    if (isSessionExpiredError(error)) {
      // Goes through the same reset as every other "this tab's session is
      // invalid" path (logOut(), checkUnlocked()'s own branch below) rather
      // than a bespoke `authenticated = false` here, and keeps this the one
      // place that logic lives if checkAuth() ever gets called again later
      // (e.g. periodic session-liveness polling). Guarded by the same
      // staleness check as the try branch above: this /api/me call can
      // still be in flight (401 not yet arrived) when e.g. Activate.svelte
      // completes in the same tab — without the check, this stale 401
      // would call markSessionExpired() and immediately log the
      // just-activated user back out.
      if (getGeneration() === startedAt) {
        markSessionExpired();
      }
    } else {
      throw error;
    }
  }
}

/**
 * Resets this tab's auth/unlock/identity state as if it had just been
 * logged out — shared by logOut() and every place that instead discovers on
 * its own that the server session died out from under this tab
 * (checkUnlocked()'s own branch below, UnlockTab.svelte's own /api/me
 * fetch). Folds together everything a dead session invalidates, rather than
 * leaving callers to remember to pair them (a previous version of this code
 * left invalidateIdentity() as a separate call some of these call sites
 * forgot, and unlockStatus reset as a separate field some of them left
 * stale — see git history):
 *
 * - unlockStatus back to 'unknown', not just `authenticated` — a same-tab
 *   relogin's markAuthenticated() only flips `authenticated`, so
 *   App.svelte's effect needs unlockStatus === 'unknown' to know to call
 *   checkUnlocked() again; leaving it stale at 'locked' here would strand
 *   that relogin on UnlockTab despite a valid password.
 * - the sessionStorage master key (crypto/session.ts) — this tab's session
 *   is gone, so any key material tied to it is now orphaned.
 * - the cached decrypted identity (crypto/identity.svelte.ts) and its own
 *   in-flight resolution, if any — otherwise a slow ensureUnlocked() call
 *   started before this could resurrect the previous identity afterward.
 */
export function markSessionExpired(): void {
  invalidateIdentity();
  clearMasterKey();
  authState.authenticated = false;
  authState.unlockStatus = 'unknown';
}

export type UnlockOutcome =
  'unlocked' | 'session-expired' | 'wrong-password' | 'error' | 'stale';

/**
 * The auth cookie is shared across tabs, but the unwrapped crypto identity
 * (in-memory, backed by this tab's own sessionStorage master key — see
 * crypto/session.ts) is not: a second tab can be authenticated while still
 * locked. checkAuth() calls this (passing the MeResponse it just fetched)
 * on every ordinary page load, and App.svelte routes to UnlockTab.svelte
 * when it comes back unlockStatus === 'locked', instead of silently
 * rendering pages that assume ensureUnlocked() already succeeded
 * (App.svelte itself ignores this function's return value — it only cares
 * about the authState side effects).
 *
 * UnlockTab.svelte also calls this itself after storing a freshly derived
 * master key — it's the real password check (ensureUnlocked()'s AEAD
 * unwrap throws a WrongPasswordError on a wrong key), not just the state
 * update — and does read the return value, to tell a genuinely wrong
 * password apart from a network/server failure fetching /api/me: both leave
 * this tab locked, but only one of them means the password was wrong.
 * Deliberately does NOT pass a meOverride there (unlike checkAuth() above):
 * the whole point of that fresh fetch is to catch a session that died, or a
 * password changed elsewhere, in the gap since this tab's own mount-time
 * fetch — passing a stale `me` would silently skip exactly that check (see
 * ensureUnlocked()'s own docblock).
 *
 * Guards against its own staleness with the shared generation counter
 * (getGeneration(), owned by crypto/identity.svelte.ts): if this call started
 * before a logOut()/markSessionExpired()/markAuthenticated() elsewhere and
 * only resolves after it (e.g. the user hit "Log out" in the header while
 * this tab's initial unlock check was still waiting on a slow /api/me), it
 * must not clobber the fresher state with its own now-outdated result —
 * 'stale' tells UnlockTab.svelte to treat it as a no-op.
 */
export async function checkUnlocked(
  meOverride?: MeResponse,
): Promise<UnlockOutcome> {
  const startedAt = getGeneration();
  try {
    await ensureUnlocked(meOverride);
    if (getGeneration() !== startedAt) return 'stale';
    authState.unlockStatus = 'unlocked';
    return 'unlocked';
  } catch (error) {
    if (getGeneration() !== startedAt) return 'stale';
    if (isSessionExpiredError(error)) {
      // The server session itself died (expired, revoked) between
      // checkAuth() and this call, or between UnlockTab storing a master
      // key and this re-fetching /api/me — not the ordinary "this tab has
      // no key yet" case. Route back to Login rather than stranding the
      // user on UnlockTab asking for a password against a session that's
      // already gone.
      markSessionExpired();
      return 'session-expired';
    }
    authState.unlockStatus = 'locked';
    if (error instanceof WrongPasswordError) {
      // Proven wrong (an AEAD authentication failure, not just "couldn't
      // check") — clear it here rather than leave that to the caller:
      // UnlockTab.svelte is the only caller today that stores a key before
      // calling this, but nothing enforces that a future one wouldn't
      // forget the cleanup step.
      clearMasterKey();
      return 'wrong-password';
    }
    // Anything else here (a transient network error, a non-401 server
    // failure) never actually got to check the key — the ordinary "this tab
    // has no key yet" case has nothing to clear anyway, but a caller that
    // *did* just store one (UnlockTab.svelte) shouldn't have it wiped over
    // a connectivity blip: leaving it in sessionStorage means a retry can
    // succeed without re-deriving it from the password again.
    return 'error';
  }
}

/**
 * Called by Login/Activate/ResetPassword.svelte after a successful login —
 * avoids a redundant round-trip to /api/me. Also marks this tab unlocked
 * directly, skipping checkUnlocked()'s own re-verification: by the time any
 * of the three call it, they've already proven this tab's master key is
 * good — Login.svelte by a local unwrapPrivateKey() check against the
 * existing wrapped key, Activate/ResetPassword.svelte because they just
 * generated a brand-new keypair and wrapped it with this exact key, so it
 * can't fail to unwrap. checkAuth()'s own checkUnlocked() call (for the
 * genuinely uncertain case — a second tab, or a page load with an existing
 * cookie) never runs a second time for a login in the same tab: it only
 * fires from App.svelte's mount effect, which has already completed by the
 * time a user gets through the login form.
 *
 * Also invalidates any already-cached identity (invalidateIdentity()):
 * Activate.svelte and ResetPassword.svelte are reachable from a link opened
 * in a tab that was already authenticated+unlocked as a *different*
 * identity (activating a second account, or resetting a password, without
 * first logging out of whoever this tab was already signed in as) — without
 * this, that tab would keep using the abandoned old keypair from
 * crypto/identity.svelte.ts's cache until a full reload, silently sealing any
 * anketa created in the meantime to a public key the server no longer has
 * a matching wrapped private key for.
 */
export function markAuthenticated(): void {
  invalidateIdentity();
  authState.authenticated = true;
  authState.checked = true;
  authState.unlockStatus = 'unlocked';
}

/**
 * Invalidates the server session and clears every trace of key material this
 * tab was holding (the unwrapped private key cached in identity.ts, the
 * master key in sessionStorage via markSessionExpired()) — logging out is
 * the one place both need to go away together, not just the server-side
 * half. Also resets the cached CSRF token: the server session invalidation
 * this triggers wipes the secret backing it, so a stale cached token would
 * otherwise fail with a genuine 403 on the very next state-changing request
 * (e.g. logging back in).
 */
export async function logOut(): Promise<void> {
  try {
    await apiPost('/api/logout', {});
  } catch {
    // Best-effort: even if invalidating the server session fails (e.g. it was
    // already gone), still clear local state so this tab stops acting logged in.
  }
  resetCsrfToken();
  markSessionExpired();
}
