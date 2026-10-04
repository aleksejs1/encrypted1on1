// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { apiGet } from '../api/client';
import type { MeResponse } from '../api/types';
import { toBase64 } from './encoding';
import {
  ensureUnlocked,
  invalidateIdentity,
  WrongPasswordError,
} from './identity.svelte';
import {
  generateKeyPair,
  packWrappedPrivateKey,
  wrapPrivateKey,
} from './keypair';
import { loadRememberedMasterKey } from './rememberedKey';
import { loadMasterKey, storeMasterKey } from './session';
import { getSodium } from './sodium';

vi.mock('../api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../api/client')>();
  return { ...actual, apiGet: vi.fn() };
});

// Only the read is mocked: ensureUnlocked() must never write to or delete
// from the store every tab shares, and would fail here if it tried.
vi.mock('./rememberedKey', () => ({
  loadRememberedMasterKey: vi.fn(),
}));

let rightKey: Uint8Array;
let wrongKey: Uint8Array;
let privateKey: Uint8Array;
let me: MeResponse;

beforeEach(async () => {
  vi.clearAllMocks();
  sessionStorage.clear();
  // The identity cache is module state: start every test logged out.
  invalidateIdentity();

  const sodium = await getSodium();
  rightKey = sodium.randombytes_buf(32);
  wrongKey = sodium.randombytes_buf(32);
  const keyPair = await generateKeyPair();
  privateKey = keyPair.privateKey;
  me = {
    id: 'user-1',
    email: 'user@example.com',
    displayName: 'User',
    isAdmin: false,
    registrationMode: 'invite',
    allowedEmailDomain: '',
    isDemo: false,
    isPlatformAdmin: false,
    publicKey: await toBase64(keyPair.publicKey),
    encryptedPrivateKey: await packWrappedPrivateKey(
      await wrapPrivateKey(keyPair.privateKey, rightKey),
    ),
  };
  vi.mocked(apiGet).mockResolvedValue(me);
  vi.mocked(loadRememberedMasterKey).mockResolvedValue(null);
});

/** An /api/me answer the test releases by hand, to act while it is in flight. */
function heldMe(): () => void {
  let release: () => void = () => {};
  vi.mocked(apiGet).mockReturnValue(
    new Promise((resolve) => {
      release = () => resolve(me);
    }),
  );
  return release;
}

describe('ensureUnlocked: where the master key comes from', () => {
  it('is not logged in with no key in the tab and none remembered, without asking the server', async () => {
    await expect(ensureUnlocked()).rejects.toThrow('Not logged in.');
    expect(apiGet).not.toHaveBeenCalled();
  });

  it("unlocks with the tab's own key and never reads the remembered one", async () => {
    await storeMasterKey(rightKey);

    const identity = await ensureUnlocked();

    expect(identity.privateKey).toEqual(privateKey);
    expect(loadRememberedMasterKey).not.toHaveBeenCalled();
  });

  it('unlocks a new tab with the remembered key and copies it into the tab', async () => {
    vi.mocked(loadRememberedMasterKey).mockResolvedValue(rightKey);

    const identity = await ensureUnlocked();

    expect(identity.privateKey).toEqual(privateKey);
    expect(await loadMasterKey()).toEqual(rightKey);
  });

  it("falls back to the remembered key when the tab's own is out of date", async () => {
    // The password was changed in another tab of this browser: that tab
    // replaced the remembered key, this one still holds the old key.
    await storeMasterKey(wrongKey);
    vi.mocked(loadRememberedMasterKey).mockResolvedValue(rightKey);

    const identity = await ensureUnlocked();

    expect(identity.privateKey).toEqual(privateKey);
    expect(await loadMasterKey()).toEqual(rightKey);
  });

  it("reports a wrong password and clears the tab's key when it is wrong and nothing is remembered", async () => {
    await storeMasterKey(wrongKey);

    await expect(ensureUnlocked()).rejects.toBeInstanceOf(WrongPasswordError);

    expect(await loadMasterKey()).toBeNull();
  });

  it('reports a wrong password for a remembered key that no longer unwraps, and leaves the tab without a key', async () => {
    vi.mocked(loadRememberedMasterKey).mockResolvedValue(wrongKey);

    await expect(ensureUnlocked()).rejects.toBeInstanceOf(WrongPasswordError);

    expect(await loadMasterKey()).toBeNull();
  });

  it("clears the tab's key when both are wrong", async () => {
    await storeMasterKey(wrongKey);
    vi.mocked(loadRememberedMasterKey).mockResolvedValue(wrongKey);

    await expect(ensureUnlocked()).rejects.toBeInstanceOf(WrongPasswordError);

    expect(await loadMasterKey()).toBeNull();
  });
});

describe('ensureUnlocked: a logout while it is running', () => {
  it('does not put the remembered key into the tab after the logout', async () => {
    vi.mocked(loadRememberedMasterKey).mockResolvedValue(rightKey);
    const release = heldMe();

    const unlocking = ensureUnlocked();
    await vi.waitFor(() => expect(apiGet).toHaveBeenCalled());
    invalidateIdentity();
    release();

    await expect(unlocking).rejects.toThrow('Not logged in.');
    expect(await loadMasterKey()).toBeNull();
  });

  it('reports stale, not a wrong password, for an unwrap that failed after the logout', async () => {
    vi.mocked(loadRememberedMasterKey).mockResolvedValue(wrongKey);
    const release = heldMe();

    const unlocking = ensureUnlocked();
    await vi.waitFor(() => expect(apiGet).toHaveBeenCalled());
    invalidateIdentity();
    release();

    await expect(unlocking).rejects.toThrow('Not logged in.');
  });

  it("does not clear a key a relogin stored in the tab while the old key's unwrap was failing", async () => {
    await storeMasterKey(wrongKey);
    const release = heldMe();

    const unlocking = ensureUnlocked();
    await vi.waitFor(() => expect(apiGet).toHaveBeenCalled());
    invalidateIdentity();
    await storeMasterKey(rightKey);
    release();

    await expect(unlocking).rejects.toThrow('Not logged in.');
    expect(await loadMasterKey()).toEqual(rightKey);
  });
});
