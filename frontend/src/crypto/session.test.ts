// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { forgetRememberedMasterKey, rememberMasterKey } from './rememberedKey';
import {
  clearMasterKey,
  loadMasterKey,
  storeLoginMasterKey,
  storeMasterKey,
} from './session';
import { getSodium } from './sodium';

vi.mock('./rememberedKey', () => ({
  rememberMasterKey: vi.fn(() => Promise.resolve()),
  forgetRememberedMasterKey: vi.fn(() => Promise.resolve()),
}));

describe('session master-key storage', () => {
  beforeEach(() => {
    sessionStorage.clear();
  });

  it('returns null when nothing is stored', async () => {
    expect(await loadMasterKey()).toBeNull();
  });

  it('round-trips a stored master-key', async () => {
    const sodium = await getSodium();
    const masterKey = sodium.randombytes_buf(32);

    await storeMasterKey(masterKey);

    expect(await loadMasterKey()).toEqual(masterKey);
  });

  it('removes the key on clear', async () => {
    const sodium = await getSodium();
    await storeMasterKey(sodium.randombytes_buf(32));

    clearMasterKey();

    expect(await loadMasterKey()).toBeNull();
  });
});

describe('storeLoginMasterKey', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    sessionStorage.clear();
  });

  it('stores the key for the tab and remembers it for a remembered login', async () => {
    const masterKey = (await getSodium()).randombytes_buf(32);

    await storeLoginMasterKey(masterKey, { secondsLeft: 600, owner: 'pk' });

    expect(await loadMasterKey()).toEqual(masterKey);
    expect(rememberMasterKey).toHaveBeenCalledExactlyOnceWith(
      masterKey,
      600,
      'pk',
    );
    expect(forgetRememberedMasterKey).not.toHaveBeenCalled();
  });

  it("stores the key for the tab and forgets an earlier login's key for an ordinary login", async () => {
    const masterKey = (await getSodium()).randombytes_buf(32);

    await storeLoginMasterKey(masterKey, null);

    expect(await loadMasterKey()).toEqual(masterKey);
    expect(forgetRememberedMasterKey).toHaveBeenCalledOnce();
    expect(rememberMasterKey).not.toHaveBeenCalled();
  });

  it('plain storeMasterKey() leaves the remembered key alone', async () => {
    await storeMasterKey((await getSodium()).randombytes_buf(32));

    expect(rememberMasterKey).not.toHaveBeenCalled();
    expect(forgetRememberedMasterKey).not.toHaveBeenCalled();
  });
});
