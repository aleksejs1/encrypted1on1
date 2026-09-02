import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, apiGet, apiPost, resetCsrfToken } from './api/client';
import type { MeResponse } from './api/types';
import {
  authState,
  checkAuth,
  checkUnlocked,
  isSessionExpiredError,
  logOut,
  markAuthenticated,
  markSessionExpired,
} from './auth.svelte';
import {
  ensureUnlocked,
  getGeneration,
  invalidateIdentity,
  WrongPasswordError,
} from './crypto/identity.svelte';
import { clearMasterKey } from './crypto/session';

vi.mock('./api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api/client')>();
  return {
    ...actual,
    apiGet: vi.fn(),
    apiPost: vi.fn(),
    resetCsrfToken: vi.fn(),
  };
});

vi.mock('./crypto/identity.svelte', async (importOriginal) => {
  const actual =
    await importOriginal<typeof import('./crypto/identity.svelte')>();
  return {
    ...actual,
    ensureUnlocked: vi.fn(),
    getGeneration: vi.fn(),
    invalidateIdentity: vi.fn(),
  };
});

vi.mock('./crypto/session', () => ({
  clearMasterKey: vi.fn(),
}));

const me: MeResponse = {
  id: 'user-1',
  email: 'user@example.com',
  displayName: 'User',
  isAdmin: false,
  registrationMode: 'invite',
  allowedEmailDomain: '',
  isDemo: false,
  isPlatformAdmin: false,
  publicKey: '',
  encryptedPrivateKey: '',
};

/** Matches an unresolved rune object field-by-field, since $state proxies aren't referentially `toEqual`-comparable across renders in every Vitest version. */
function expectAuthState(expected: {
  checked: boolean;
  authenticated: boolean;
  unlockStatus: 'unknown' | 'locked' | 'unlocked';
}): void {
  expect(authState.checked).toBe(expected.checked);
  expect(authState.authenticated).toBe(expected.authenticated);
  expect(authState.unlockStatus).toBe(expected.unlockStatus);
}

beforeEach(() => {
  vi.clearAllMocks();
  authState.checked = false;
  authState.authenticated = false;
  authState.unlockStatus = 'unknown';
  // Stable by default — individual staleness tests override with a
  // mockReturnValueOnce chain to simulate the generation counter moving
  // mid-flight.
  vi.mocked(getGeneration).mockReturnValue(0);
});

describe('isSessionExpiredError', () => {
  it('is true only for a 401 ApiError', () => {
    expect(isSessionExpiredError(new ApiError(401, 'unauthorized'))).toBe(true);
  });

  it('is false for other ApiError statuses', () => {
    expect(isSessionExpiredError(new ApiError(403, 'forbidden'))).toBe(false);
  });

  it('is false for a non-ApiError', () => {
    expect(isSessionExpiredError(new Error('network down'))).toBe(false);
  });
});

describe('checkAuth', () => {
  it('authenticates and unlocks on a clean /api/me + unwrap', async () => {
    vi.mocked(apiGet).mockResolvedValue(me);
    vi.mocked(ensureUnlocked).mockResolvedValue({} as never);

    await checkAuth();

    expectAuthState({
      checked: true,
      authenticated: true,
      unlockStatus: 'unlocked',
    });
  });

  it('bails without touching state if the generation moves during /api/me', async () => {
    vi.mocked(getGeneration).mockReturnValueOnce(0).mockReturnValueOnce(1);
    vi.mocked(apiGet).mockResolvedValue(me);

    await checkAuth();

    expectAuthState({
      checked: false,
      authenticated: false,
      unlockStatus: 'unknown',
    });
    expect(ensureUnlocked).not.toHaveBeenCalled();
  });

  it('marks the session expired on a 401 from /api/me', async () => {
    vi.mocked(apiGet).mockRejectedValue(new ApiError(401, 'unauthorized'));

    await checkAuth();

    expectAuthState({
      checked: true,
      authenticated: false,
      unlockStatus: 'unknown',
    });
    expect(invalidateIdentity).toHaveBeenCalledOnce();
    expect(clearMasterKey).toHaveBeenCalledOnce();
  });

  it('does not clobber fresher state if the generation moved before the 401 arrived', async () => {
    vi.mocked(getGeneration).mockReturnValueOnce(0).mockReturnValueOnce(1);
    vi.mocked(apiGet).mockRejectedValue(new ApiError(401, 'unauthorized'));
    // Simulate a same-tab relogin having already run and set fresher state.
    authState.authenticated = true;
    authState.unlockStatus = 'unlocked';

    await checkAuth();

    // `checked` is always set, but the fresher authenticated/unlockStatus
    // set by the concurrent relogin must survive this stale call's catch.
    expect(authState.checked).toBe(true);
    expect(authState.authenticated).toBe(true);
    expect(authState.unlockStatus).toBe('unlocked');
    expect(invalidateIdentity).not.toHaveBeenCalled();
  });

  it('rethrows a non-401 failure', async () => {
    vi.mocked(apiGet).mockRejectedValue(new Error('server on fire'));

    await expect(checkAuth()).rejects.toThrow('server on fire');
    expect(authState.checked).toBe(true);
  });
});

describe('checkUnlocked', () => {
  it('unlocks on a successful unwrap', async () => {
    vi.mocked(ensureUnlocked).mockResolvedValue({} as never);

    await expect(checkUnlocked(me)).resolves.toBe('unlocked');
    expect(authState.unlockStatus).toBe('unlocked');
  });

  it('reports stale and leaves state alone if the generation moved during the unwrap', async () => {
    vi.mocked(getGeneration).mockReturnValueOnce(0).mockReturnValueOnce(1);
    vi.mocked(ensureUnlocked).mockResolvedValue({} as never);

    await expect(checkUnlocked(me)).resolves.toBe('stale');
    expect(authState.unlockStatus).toBe('unknown');
  });

  it('treats a 401 during the unwrap as a dead session, not a wrong password', async () => {
    vi.mocked(ensureUnlocked).mockRejectedValue(
      new ApiError(401, 'unauthorized'),
    );

    await expect(checkUnlocked(me)).resolves.toBe('session-expired');
    expect(authState.authenticated).toBe(false);
    expect(authState.unlockStatus).toBe('unknown');
    expect(invalidateIdentity).toHaveBeenCalledOnce();
  });

  it('reports stale ahead of a 401, even if the underlying error was session-expired', async () => {
    vi.mocked(getGeneration).mockReturnValueOnce(0).mockReturnValueOnce(1);
    vi.mocked(ensureUnlocked).mockRejectedValue(
      new ApiError(401, 'unauthorized'),
    );

    await expect(checkUnlocked(me)).resolves.toBe('stale');
    expect(invalidateIdentity).not.toHaveBeenCalled();
  });

  it('clears the master key on a genuinely wrong password', async () => {
    vi.mocked(ensureUnlocked).mockRejectedValue(new WrongPasswordError());

    await expect(checkUnlocked(me)).resolves.toBe('wrong-password');
    expect(authState.unlockStatus).toBe('locked');
    expect(clearMasterKey).toHaveBeenCalledOnce();
  });

  it('leaves the master key alone on a transient failure', async () => {
    vi.mocked(ensureUnlocked).mockRejectedValue(new Error('network blip'));

    await expect(checkUnlocked(me)).resolves.toBe('error');
    expect(authState.unlockStatus).toBe('locked');
    expect(clearMasterKey).not.toHaveBeenCalled();
  });
});

describe('markSessionExpired', () => {
  it('invalidates identity, clears the master key, and resets auth/unlock state', () => {
    authState.authenticated = true;
    authState.unlockStatus = 'unlocked';

    markSessionExpired();

    expect(invalidateIdentity).toHaveBeenCalledOnce();
    expect(clearMasterKey).toHaveBeenCalledOnce();
    expect(authState.authenticated).toBe(false);
    expect(authState.unlockStatus).toBe('unknown');
  });
});

describe('markAuthenticated', () => {
  it('invalidates any previously cached identity and marks this tab unlocked', () => {
    markAuthenticated();

    expect(invalidateIdentity).toHaveBeenCalledOnce();
    expectAuthState({
      checked: true,
      authenticated: true,
      unlockStatus: 'unlocked',
    });
  });
});

describe('logOut', () => {
  it('invalidates the server session and resets local state', async () => {
    vi.mocked(apiPost).mockResolvedValue(undefined);
    authState.authenticated = true;
    authState.unlockStatus = 'unlocked';

    await logOut();

    expect(apiPost).toHaveBeenCalledWith('/api/logout', {});
    expect(resetCsrfToken).toHaveBeenCalledOnce();
    expectAuthState({
      checked: false,
      authenticated: false,
      unlockStatus: 'unknown',
    });
  });

  it('still clears local state if the server logout call fails', async () => {
    vi.mocked(apiPost).mockRejectedValue(new Error('network down'));
    authState.authenticated = true;
    authState.unlockStatus = 'unlocked';

    await logOut();

    expect(authState.authenticated).toBe(false);
    expect(authState.unlockStatus).toBe('unknown');
  });
});
