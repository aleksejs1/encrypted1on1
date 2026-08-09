import { fromBase64, toBase64 } from './encoding';
import { getSodium } from './sodium';

export async function generateAnketaKey(): Promise<Uint8Array> {
  const sodium = await getSodium();
  return sodium.randombytes_buf(sodium.crypto_aead_xchacha20poly1305_ietf_KEYBYTES);
}

/** `crypto_box_seal`: anonymous — no sender key needed, only the recipient's public key. */
export async function sealAnketaKey(anketaKey: Uint8Array, publicKey: Uint8Array): Promise<string> {
  const sodium = await getSodium();
  return toBase64(sodium.crypto_box_seal(anketaKey, publicKey));
}

export async function unsealAnketaKey(
  sealed: string,
  publicKey: Uint8Array,
  privateKey: Uint8Array,
): Promise<Uint8Array> {
  const sodium = await getSodium();
  return sodium.crypto_box_seal_open(await fromBase64(sealed), publicKey, privateKey);
}

const SCHEMA_VERSION = 1;

interface Envelope<T> {
  schemaVersion: number;
  data: T;
}

/**
 * The versioned envelope from the spec, packed as nonce||ciphertext base64 —
 * same shape as keypair.ts's pack/unpack, reusing the same AEAD primitive
 * rather than introducing a second one for "blob" vs "private key" content.
 */
export async function encryptBlob<T>(data: T, key: Uint8Array): Promise<string> {
  const sodium = await getSodium();
  const envelope: Envelope<T> = { schemaVersion: SCHEMA_VERSION, data };
  const plaintext = new TextEncoder().encode(JSON.stringify(envelope));

  const nonce = sodium.randombytes_buf(sodium.crypto_aead_xchacha20poly1305_ietf_NPUBBYTES);
  const ciphertext = sodium.crypto_aead_xchacha20poly1305_ietf_encrypt(plaintext, null, null, nonce, key);

  const combined = new Uint8Array(nonce.length + ciphertext.length);
  combined.set(nonce, 0);
  combined.set(ciphertext, nonce.length);
  return toBase64(combined);
}

export async function decryptBlob<T>(packed: string, key: Uint8Array): Promise<Envelope<T>> {
  const sodium = await getSodium();
  const combined = await fromBase64(packed);
  const nonceLength = sodium.crypto_aead_xchacha20poly1305_ietf_NPUBBYTES;
  const nonce = combined.slice(0, nonceLength);
  const ciphertext = combined.slice(nonceLength);

  const plaintext = sodium.crypto_aead_xchacha20poly1305_ietf_decrypt(null, ciphertext, null, nonce, key);
  return JSON.parse(new TextDecoder().decode(plaintext)) as Envelope<T>;
}
