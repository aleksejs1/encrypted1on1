import { getSodium } from './sodium';

export interface DerivedKeys {
  /** Sent to the server to verify login. Never the password, never the master-key. */
  authKey: Uint8Array;
  /** Never leaves the browser. */
  masterKey: Uint8Array;
}

const KEY_LENGTH = 32;

/**
 * argon2id → HKDF-SHA256 split into two independent branches ("auth" /
 * "master-key"), per the spec's crypto model.
 *
 * The HKDF step uses native WebCrypto (`crypto.subtle`), not libsodium: even
 * the sumo build of libsodium-wrappers exposes crypto_kdf_hkdf_sha256_*
 * byte-length constants but not the actual extract/expand functions
 * (checked against the installed build) — HKDF is, unlike argon2id/X25519,
 * a standard algorithm WebCrypto natively and correctly implements, so this
 * isn't fighting the platform the way avoiding WebCrypto for argon2id/X25519
 * would be.
 */
export async function deriveKeysFromPassword(
  password: string,
  salt: Uint8Array,
): Promise<DerivedKeys> {
  const sodium = await getSodium();

  const intermediateKey = sodium.crypto_pwhash(
    KEY_LENGTH,
    password,
    salt,
    sodium.crypto_pwhash_OPSLIMIT_INTERACTIVE,
    sodium.crypto_pwhash_MEMLIMIT_INTERACTIVE,
    sodium.crypto_pwhash_ALG_ARGON2ID13,
  );

  // .slice() guarantees a plain ArrayBuffer-backed view — importKey's BufferSource
  // type rejects the ArrayBufferLike type sodium.crypto_pwhash returns otherwise.
  const ikm = await crypto.subtle.importKey('raw', intermediateKey.slice(), 'HKDF', false, [
    'deriveBits',
  ]);

  const [authBits, masterBits] = await Promise.all([
    deriveHkdfBits(ikm, 'auth'),
    deriveHkdfBits(ikm, 'master-key'),
  ]);

  return {
    authKey: new Uint8Array(authBits),
    masterKey: new Uint8Array(masterBits),
  };
}

async function deriveHkdfBits(ikm: CryptoKey, info: string): Promise<ArrayBuffer> {
  return crypto.subtle.deriveBits(
    {
      name: 'HKDF',
      hash: 'SHA-256',
      salt: new Uint8Array(0),
      info: new TextEncoder().encode(info),
    },
    ikm,
    KEY_LENGTH * 8,
  );
}
