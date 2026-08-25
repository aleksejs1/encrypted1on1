import { apiGet } from '../api/client';
import type { MeResponse } from '../api/types';
import { setDisplayNameState } from '../displayName.svelte';
import { fromBase64 } from './encoding';
import { unpackWrappedPrivateKey, unwrapPrivateKey } from './keypair';
import { loadMasterKey } from './session';

export interface Identity {
  userId: string;
  email: string;
  /** Optional, plaintext — empty string means not set. See userDisplay.ts for how this is shown. */
  displayName: string;
  isAdmin: boolean;
  /** "invite" | "admin_only" | "domain" (Phase 6g) — every authenticated user needs this to decide whether to show the general "Invite" UI. */
  registrationMode: string;
  /** Empty string = unrestricted. Non-sensitive; used by AdminPanel.svelte's invite-settings card. */
  allowedEmailDomain: string;
  /** True only for the fixed, publicly-known demo account — drives a persistent "shared demo" banner. See private/demo-mode-plan.md (not tracked in git). */
  isDemo: boolean;
  /** The SaaS operator's own cross-company role (Phase C, private/cloud-service-plan.md, not tracked in git) — completely separate from isAdmin. Never drives a navigation link, only gates /platform-admin's own content. */
  isPlatformAdmin: boolean;
  publicKey: Uint8Array;
  privateKey: Uint8Array;
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
      displayName: me.displayName,
      isAdmin: me.isAdmin,
      registrationMode: me.registrationMode,
      allowedEmailDomain: me.allowedEmailDomain,
      isDemo: me.isDemo,
      isPlatformAdmin: me.isPlatformAdmin,
      publicKey: await fromBase64(me.publicKey),
      privateKey,
    };
    cached = identity;
    setDisplayNameState(identity.displayName);
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
  setDisplayNameState('');
}

/** Called by AccountSettings.svelte after saving a new display name — updates both the cached Identity and the reactive mirror AppHeader.svelte reads from. */
export function updateCachedDisplayName(displayName: string): void {
  if (cached) cached.displayName = displayName;
  setDisplayNameState(displayName);
}
