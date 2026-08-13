import { getSodium } from './sodium';

/**
 * Deterministic argon2id salt derived from the (normalized) email — the
 * decision deferred from Phase 3. Chosen over a random salt stored per-user
 * on the server: no extra DB column, no network round-trip before password
 * entry, and no "does this email have a salt" endpoint that would otherwise
 * become an email-enumeration side channel.
 */
export async function deriveArgon2idSalt(email: string): Promise<Uint8Array> {
  const sodium = await getSodium();
  const normalized = email.trim().toLowerCase();
  return sodium.crypto_generichash(
    sodium.crypto_pwhash_SALTBYTES,
    normalized,
    null,
  );
}
