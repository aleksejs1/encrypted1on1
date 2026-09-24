import { describe, expect, it } from 'vitest';
import {
  deriveDraftKey,
  generateKeyPair,
  packWrappedPrivateKey,
  unpackWrappedPrivateKey,
  unwrapPrivateKey,
  wrapPrivateKey,
} from './keypair';
import { getSodium } from './sodium';

describe('keypair', () => {
  it('generates a public/private X25519 pair', async () => {
    const { publicKey, privateKey } = await generateKeyPair();

    expect(publicKey).toHaveLength(32);
    expect(privateKey).toHaveLength(32);
    expect(publicKey).not.toEqual(privateKey);
  });

  it('round-trips a wrapped private key with the correct master-key', async () => {
    const sodium = await getSodium();
    const { privateKey } = await generateKeyPair();
    const masterKey = sodium.randombytes_buf(32);

    const wrapped = await wrapPrivateKey(privateKey, masterKey);
    const unwrapped = await unwrapPrivateKey(wrapped, masterKey);

    expect(unwrapped).toEqual(privateKey);
  });

  it('fails to unwrap with the wrong master-key instead of returning garbage', async () => {
    const sodium = await getSodium();
    const { privateKey } = await generateKeyPair();
    const masterKey = sodium.randombytes_buf(32);
    const wrongKey = sodium.randombytes_buf(32);

    const wrapped = await wrapPrivateKey(privateKey, masterKey);

    await expect(unwrapPrivateKey(wrapped, wrongKey)).rejects.toThrow();
  });

  it('round-trips through pack/unpack for transport', async () => {
    const sodium = await getSodium();
    const { privateKey } = await generateKeyPair();
    const masterKey = sodium.randombytes_buf(32);

    const wrapped = await wrapPrivateKey(privateKey, masterKey);
    const packed = await packWrappedPrivateKey(wrapped);
    const unpacked = await unpackWrappedPrivateKey(packed);
    const unwrapped = await unwrapPrivateKey(unpacked, masterKey);

    expect(unwrapped).toEqual(privateKey);
  });

  it('derives a stable 32-byte draft key, distinct from the private key itself', async () => {
    const { privateKey } = await generateKeyPair();

    const draftKey = await deriveDraftKey(privateKey);

    expect(draftKey).toHaveLength(32);
    expect(draftKey).not.toEqual(privateKey);
    expect(await deriveDraftKey(privateKey)).toEqual(draftKey);
  });

  it('derives the draft key exactly as specified (known-answer vector)', async () => {
    // crypto_kdf = keyed BLAKE2b: key = private key, salt = subkey id 1 (LE,
    // zero-padded), personal = 'e1o1drft'. Independently reproduced with
    // Python's hashlib.blake2b. Changing any parameter would strand every
    // stored draft — this test is what stops that happening silently.
    const sodium = await getSodium();
    const privateKey = new Uint8Array(32).map((_, i) => i);

    const draftKey = await deriveDraftKey(privateKey);

    expect(sodium.to_hex(draftKey)).toBe(
      '4f820ea8ec91f683cb121bdd13d69a476ba737c65ae71ea0d06c62863147276a',
    );
  });

  it('derives a different draft key for a different keypair', async () => {
    const first = await generateKeyPair();
    const second = await generateKeyPair();

    expect(await deriveDraftKey(first.privateKey)).not.toEqual(
      await deriveDraftKey(second.privateKey),
    );
  });
});
