import { fromBase64, toBase64 } from './encoding';
import { forgetRememberedMasterKey, rememberMasterKey } from './rememberedKey';

const STORAGE_KEY = 'e1o1:master-key';

/**
 * sessionStorage, per the spec's decision: survives an accidental refresh,
 * does not survive closing the tab, and is never persisted between browser
 * sessions. The one opt-in exception is crypto/rememberedKey.ts ("Remember
 * this browser"), a separate store this one is filled from on a new tab.
 */
export async function storeMasterKey(masterKey: Uint8Array): Promise<void> {
  storeEncodedMasterKey(await toBase64(masterKey));
}

/**
 * storeMasterKey()'s second half, for a caller that has to store without
 * awaiting: ensureUnlocked() (crypto/identity.svelte.ts) encodes the key
 * first and writes it in the same synchronous step as its last staleness
 * check, so a logout landing in between can't be followed by the key
 * reappearing.
 */
export function storeEncodedMasterKey(encoded: string): void {
  sessionStorage.setItem(STORAGE_KEY, encoded);
}

/**
 * What every screen that logs this tab in does with the key (Login, Activate,
 * ResetPassword): stores it for the tab, and makes the browser's remembered
 * key match the new login — written when the server made it a remembered one
 * (`remembered`: the seconds it has left, and the user's public key),
 * deleted otherwise, so no earlier login's key stays behind. One function,
 * so a login path can't do the first half and forget the second.
 */
export async function storeLoginMasterKey(
  masterKey: Uint8Array,
  remembered: { secondsLeft: number; owner: string } | null,
): Promise<void> {
  await storeMasterKey(masterKey);
  if (remembered !== null) {
    void rememberMasterKey(masterKey, remembered.secondsLeft, remembered.owner);
  } else {
    void forgetRememberedMasterKey();
  }
}

export async function loadMasterKey(): Promise<Uint8Array | null> {
  const stored = sessionStorage.getItem(STORAGE_KEY);
  return stored === null ? null : fromBase64(stored);
}

export function clearMasterKey(): void {
  sessionStorage.removeItem(STORAGE_KEY);
}
