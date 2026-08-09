import { apiGet } from '../api/client';
import { fromBase64 } from './encoding';
import { unpackWrappedPrivateKey, unwrapPrivateKey } from './keypair';
import { loadMasterKey } from './session';

export interface Identity {
  userId: string;
  email: string;
  isAdmin: boolean;
  publicKey: Uint8Array;
  privateKey: Uint8Array;
}

interface MeResponse {
  id: string;
  email: string;
  isAdmin: boolean;
  publicKey: string;
  encryptedPrivateKey: string;
}

let cached: Identity | null = null;
let inflight: Promise<Identity> | null = null;

/**
 * Re-derives the unwrapped private key from the sessionStorage master-key
 * (Phase 3) plus /api/me's encryptedPrivateKey (Phase 5) — the private key
 * itself lives only in this module-level variable, never in any browser
 * storage. Memoized for the tab's lifetime; a page refresh clears the cache
 * and this runs again (cheap: an AEAD decrypt, not a fresh argon2id run).
 */
export async function ensureUnlocked(): Promise<Identity> {
  if (cached) return cached;
  if (inflight) return inflight;

  inflight = (async () => {
    const masterKey = await loadMasterKey();
    if (!masterKey) {
      throw new Error('Not logged in.');
    }

    const me = await apiGet<MeResponse>('/api/me');
    const wrapped = await unpackWrappedPrivateKey(me.encryptedPrivateKey);
    const privateKey = await unwrapPrivateKey(wrapped, masterKey);

    const identity: Identity = {
      userId: me.id,
      email: me.email,
      isAdmin: me.isAdmin,
      publicKey: await fromBase64(me.publicKey),
      privateKey,
    };
    cached = identity;
    return identity;
  })();

  try {
    return await inflight;
  } finally {
    inflight = null;
  }
}

export function clearIdentity(): void {
  cached = null;
}
