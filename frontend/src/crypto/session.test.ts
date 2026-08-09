// @vitest-environment jsdom
import { beforeEach, describe, expect, it } from 'vitest';
import { clearMasterKey, loadMasterKey, storeMasterKey } from './session';
import { getSodium } from './sodium';

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
