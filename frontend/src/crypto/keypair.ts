import { fromBase64, toBase64 } from './encoding';
import { getSodium } from './sodium';

export interface KeyPair {
  publicKey: Uint8Array;
  privateKey: Uint8Array;
}

export interface WrappedPrivateKey {
  nonce: Uint8Array;
  ciphertext: Uint8Array;
}

export async function generateKeyPair(): Promise<KeyPair> {
  const sodium = await getSodium();
  const { publicKey, privateKey } = sodium.crypto_box_keypair();
  return { publicKey, privateKey };
}

/** Wraps a private key with the user's master-key (XChaCha20-Poly1305 AEAD, per spec). Nonce isn't secret — stored alongside the ciphertext. */
export async function wrapPrivateKey(
  privateKey: Uint8Array,
  masterKey: Uint8Array,
): Promise<WrappedPrivateKey> {
  const sodium = await getSodium();
  const nonce = sodium.randombytes_buf(sodium.crypto_aead_xchacha20poly1305_ietf_NPUBBYTES);
  const ciphertext = sodium.crypto_aead_xchacha20poly1305_ietf_encrypt(
    privateKey,
    null,
    null,
    nonce,
    masterKey,
  );
  return { nonce, ciphertext };
}

/** Throws if `masterKey` is wrong (AEAD authentication failure) rather than returning garbage. */
export async function unwrapPrivateKey(
  wrapped: WrappedPrivateKey,
  masterKey: Uint8Array,
): Promise<Uint8Array> {
  const sodium = await getSodium();
  return sodium.crypto_aead_xchacha20poly1305_ietf_decrypt(
    null,
    wrapped.ciphertext,
    null,
    wrapped.nonce,
    masterKey,
  );
}

/**
 * Packs {nonce, ciphertext} into one base64 string for transport/storage —
 * the API and the `users` table each have a single `encryptedPrivateKey`
 * column/field, not two. The nonce is fixed-length, so unpacking is a plain
 * slice, not a delimiter-based format that could be ambiguous.
 */
export async function packWrappedPrivateKey(wrapped: WrappedPrivateKey): Promise<string> {
  const combined = new Uint8Array(wrapped.nonce.length + wrapped.ciphertext.length);
  combined.set(wrapped.nonce, 0);
  combined.set(wrapped.ciphertext, wrapped.nonce.length);
  return toBase64(combined);
}

export async function unpackWrappedPrivateKey(packed: string): Promise<WrappedPrivateKey> {
  const sodium = await getSodium();
  const combined = await fromBase64(packed);
  const nonceLength = sodium.crypto_aead_xchacha20poly1305_ietf_NPUBBYTES;
  return {
    nonce: combined.slice(0, nonceLength),
    ciphertext: combined.slice(nonceLength),
  };
}
