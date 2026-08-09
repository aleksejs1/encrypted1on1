import { describe, expect, it } from 'vitest';
import {
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
});
