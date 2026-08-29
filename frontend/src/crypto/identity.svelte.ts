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

/** Thrown by ensureUnlocked() specifically when the AEAD unwrap rejects the master key — i.e. an actually wrong password, distinct from a network/server failure fetching /api/me (see auth.svelte.ts's checkUnlocked(), which relies on this to tell the two apart). */
export class WrongPasswordError extends Error {
  constructor() {
    super('Incorrect password.');
    this.name = 'WrongPasswordError';
  }
}

let cached: Identity | null = null;
let inflight: Promise<Identity> | null = null;
/**
 * The single source of truth for "has this tab's identity been invalidated
 * since some earlier point." Bumped only by invalidateIdentity() (the real
 * "this tab is no longer logged in" case), not by the plain clearIdentity()
 * below (a routine post-save cache-bust — see its own docblock) — so a slow
 * ensureUnlocked() call started before an actual logout can't resurrect the
 * previous user's identity into `cached` after the logout already wiped it,
 * without also poisoning an unrelated concurrent call across a routine
 * cache refresh. See the checks in ensureUnlocked() below.
 *
 * A real $state, not a plain module variable, so a Svelte $effect that
 * calls getGeneration() (AppHeader.svelte does, specifically to detect a
 * same-tab reauth as a different identity even when authState's own fields
 * happen to hold the same values before and after) is tracked as a
 * dependency and correctly re-runs on every bump — a plain `let` wouldn't
 * be visible to Svelte's reactivity at all. auth.svelte.ts reads this
 * instead of keeping its own separate counter: two independently-bumped
 * counters previously stood in for this and once fell out of sync (a
 * caller that remembered to invalidate the identity cache but not
 * auth.svelte.ts's own copy) — see
 * docs/decisions/2026-08-28-multi-tab-unlock-state-machine.md.
 */
let generation = $state(0);

export function getGeneration(): number {
  return generation;
}

/**
 * Re-derives the unwrapped private key from the sessionStorage master-key
 * (Phase 3) plus /api/me's encryptedPrivateKey (Phase 5) — the private key
 * itself lives only in this module-level variable, never in any browser
 * storage. Memoized for the tab's lifetime; a page refresh clears the cache
 * and this runs again (cheap: an AEAD decrypt, not a fresh argon2id run).
 *
 * `meOverride` lets a caller that just fetched /api/me itself, in the same
 * synchronous flow, skip the otherwise-redundant second round trip — used
 * by auth.svelte.ts's checkAuth(), which already has the response it needs.
 * Only safe when there's no gap between the fetch and this call: a caller
 * that fetched `me` earlier and might reuse it after arbitrary delay (e.g.
 * waiting on user input) must NOT pass it here — the whole point of the
 * fresh fetch is to catch a session that died or an encryptedPrivateKey
 * that changed in that gap, which passing a stale `me` would silently skip
 * (see UnlockTab.svelte, which fetches its own `me` at mount but
 * deliberately does not pass it to checkUnlocked() at submit time).
 */
export async function ensureUnlocked(
  meOverride?: MeResponse,
): Promise<Identity> {
  if (cached) return cached;
  if (inflight) return inflight;

  const startedAt = generation;
  const thisCall: Promise<Identity> = (async () => {
    const masterKey = await loadMasterKey();
    if (!masterKey) {
      throw new Error('Not logged in.');
    }

    // Checked here too, not just right before caching below: an
    // invalidation (a relogin as a different identity in this same tab)
    // that landed between snapshotting `startedAt` and this masterKey read
    // means `masterKey` may already belong to that different identity —
    // unwrapping *this* call's `me` (the identity this call started for)
    // against it would throw a WrongPasswordError that has nothing to do
    // with an actual wrong password, instead of the accurate "stale" signal.
    if (generation !== startedAt) {
      throw new Error('Not logged in.');
    }

    const me = meOverride ?? (await apiGet<MeResponse>('/api/me'));
    const wrapped = await unpackWrappedPrivateKey(me.encryptedPrivateKey);
    let privateKey: Uint8Array;
    try {
      privateKey = await unwrapPrivateKey(wrapped, masterKey);
    } catch {
      // An invalidation landing during this specific await (the narrowest
      // remaining window: a same-tab relogin racing the exact moment this
      // call is unwrapping) would otherwise surface as a false "wrong
      // password" instead of the accurate stale/no-op signal, since a
      // mismatched masterKey/me pairing from a mid-flight identity switch
      // fails this unwrap the same way an actually wrong password does.
      throw generation !== startedAt
        ? new Error('Not logged in.')
        : new WrongPasswordError();
    }

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

    // Checked one last time here — after every await in this function,
    // immediately before the synchronous commit below — not earlier: a
    // logout (invalidateIdentity(), which bumps `generation`) that ran
    // while any of the awaits above were in flight must win, otherwise this
    // call, started before the logout, would overwrite the `cached = null`
    // that logout already set with the previous user's decrypted identity.
    // An earlier check (e.g. right after unwrapPrivateKey) would still
    // leave the `await fromBase64(...)` above as an unguarded gap.
    if (generation !== startedAt) {
      throw new Error('Not logged in.');
    }

    cached = identity;
    setDisplayNameState(identity.displayName);
    return identity;
  })();
  inflight = thisCall;

  try {
    return await thisCall;
  } finally {
    // Only clear `inflight` if it's still pointing at this call's own
    // promise — invalidateIdentity() may have already replaced it with a
    // newer call's promise (e.g. an immediate relogin) while this one was
    // still settling; unconditionally nulling it here would then wipe out
    // the reference to that newer, still-pending call, letting a third
    // caller bypass the `if (inflight) return inflight` de-dup above and
    // start its own redundant fetch instead of joining it.
    if (inflight === thisCall) {
      inflight = null;
    }
  }
}

/**
 * Clears the cached identity so the next ensureUnlocked() call re-fetches
 * from /api/me — used for routine "this cached data is now stale" refreshes
 * (e.g. AdminPanel.svelte's saveInviteSettings(), after a self-service
 * change to registrationMode/allowedEmailDomain). Deliberately does NOT
 * bump `generation`: this isn't a logout, so it must not poison an
 * unrelated concurrent ensureUnlocked() call that's still legitimately in
 * flight — see invalidateIdentity() for that case.
 *
 * Accepted residual gap: an ensureUnlocked() call already in flight (same
 * generation) when this runs isn't cancelled, so it can still resolve
 * afterward and set `cached` back to the pre-bust snapshot it already
 * fetched, undoing this call's effect until the next natural cache miss.
 * Narrow (requires another caller's fetch to have started in the same
 * instant as this save) and low-stakes (stale non-secret UI fields
 * — registrationMode/allowedEmailDomain — not key material), so left as is
 * rather than adding a second invalidation mechanism to close it.
 */
export function clearIdentity(): void {
  cached = null;
  setDisplayNameState('');
}

/**
 * Like clearIdentity(), but also invalidates any identity resolution
 * already in flight — for auth.svelte.ts's logOut(), the actual "this tab
 * is no longer logged in" case. Resetting `inflight` (not just bumping
 * `generation`) matters too: without it, a fresh ensureUnlocked() call
 * right after a logout (e.g. from an immediate relogin) would attach itself
 * to the old, now-doomed-to-fail promise via the `if (inflight) return
 * inflight;` memoization above, instead of starting its own — every caller
 * already holding a reference to the old promise is unaffected, since
 * clearing this module-level variable doesn't change what they're awaiting.
 */
export function invalidateIdentity(): void {
  generation++;
  inflight = null;
  clearIdentity();
}

/** Called by AccountSettings.svelte after saving a new display name — updates both the cached Identity and the reactive mirror AppHeader.svelte reads from. */
export function updateCachedDisplayName(displayName: string): void {
  if (cached) cached.displayName = displayName;
  setDisplayNameState(displayName);
}
