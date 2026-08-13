import { apiGet, apiPost, ApiError, resetCsrfToken } from './api/client';
import { clearIdentity } from './crypto/identity';
import { clearMasterKey } from './crypto/session';

export const authState = $state<{ checked: boolean; authenticated: boolean }>({
  checked: false,
  authenticated: false,
});

export async function checkAuth(): Promise<void> {
  try {
    await apiGet('/api/me');
    authState.authenticated = true;
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      authState.authenticated = false;
    } else {
      throw error;
    }
  } finally {
    authState.checked = true;
  }
}

/** Called by Login/Activate after a successful login — avoids a redundant round-trip to /api/me. */
export function markAuthenticated(): void {
  authState.authenticated = true;
  authState.checked = true;
}

/**
 * Invalidates the server session and clears every trace of key material this
 * tab was holding (the unwrapped private key cached in identity.ts, the
 * master key in sessionStorage) — logging out is the one place both need to
 * go away together, not just the server-side half. Also resets the cached
 * CSRF token: the server session invalidation this triggers wipes the secret
 * backing it, so a stale cached token would otherwise fail with a genuine
 * 403 on the very next state-changing request (e.g. logging back in).
 */
export async function logOut(): Promise<void> {
  try {
    await apiPost('/api/logout', {});
  } catch {
    // Best-effort: even if invalidating the server session fails (e.g. it was
    // already gone), still clear local state so this tab stops acting logged in.
  }
  resetCsrfToken();
  clearMasterKey();
  clearIdentity();
  authState.authenticated = false;
}
