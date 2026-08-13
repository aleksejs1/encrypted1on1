import { describe, expect, it } from 'vitest';
import {
  decryptBlob,
  encryptBlob,
  generateAnketaKey,
  sealAnketaKey,
  unsealAnketaKey,
} from './anketaKey';
import { generateKeyPair } from './keypair';

describe('anketa key sealing', () => {
  it('seals and unseals the anketa key for the intended recipient', async () => {
    const anketaKey = await generateAnketaKey();
    const { publicKey, privateKey } = await generateKeyPair();

    const sealed = await sealAnketaKey(anketaKey, publicKey);
    const unsealed = await unsealAnketaKey(sealed, publicKey, privateKey);

    expect(unsealed).toEqual(anketaKey);
  });

  it('fails to unseal with the wrong keypair', async () => {
    const anketaKey = await generateAnketaKey();
    const { publicKey } = await generateKeyPair();
    const wrongKeyPair = await generateKeyPair();

    const sealed = await sealAnketaKey(anketaKey, publicKey);

    await expect(
      unsealAnketaKey(sealed, publicKey, wrongKeyPair.privateKey),
    ).rejects.toThrow();
  });
});

describe('blob envelope', () => {
  it('round-trips data through the versioned envelope', async () => {
    const key = await generateAnketaKey();
    const data = { mood: 'good', notes: ['first entry', 'second entry'] };

    const packed = await encryptBlob(data, key);
    const envelope = await decryptBlob<typeof data>(packed, key);

    expect(envelope.schemaVersion).toBe(1);
    expect(envelope.data).toEqual(data);
  });

  it('fails to decrypt with the wrong key', async () => {
    const key = await generateAnketaKey();
    const wrongKey = await generateAnketaKey();

    const packed = await encryptBlob({ x: 1 }, key);

    await expect(decryptBlob(packed, wrongKey)).rejects.toThrow();
  });
});
