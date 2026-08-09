import { describe, expect, it } from 'vitest';
import { deriveKeysFromPassword } from './password';
import { getSodium } from './sodium';

async function testSalt(fill: number): Promise<Uint8Array> {
  const sodium = await getSodium();
  return new Uint8Array(sodium.crypto_pwhash_SALTBYTES).fill(fill);
}

describe('deriveKeysFromPassword', () => {
  it('is deterministic for the same password and salt', async () => {
    const salt = await testSalt(1);
    const a = await deriveKeysFromPassword('correct horse battery staple', salt);
    const b = await deriveKeysFromPassword('correct horse battery staple', salt);

    expect(a.authKey).toEqual(b.authKey);
    expect(a.masterKey).toEqual(b.masterKey);
  });

  it('produces independent authKey and masterKey', async () => {
    const salt = await testSalt(2);
    const { authKey, masterKey } = await deriveKeysFromPassword('hunter2', salt);

    expect(authKey).toHaveLength(32);
    expect(masterKey).toHaveLength(32);
    expect(authKey).not.toEqual(masterKey);
  });

  it('produces different keys for a different password', async () => {
    const salt = await testSalt(3);
    const a = await deriveKeysFromPassword('password-one', salt);
    const b = await deriveKeysFromPassword('password-two', salt);

    expect(a.authKey).not.toEqual(b.authKey);
    expect(a.masterKey).not.toEqual(b.masterKey);
  });
});
