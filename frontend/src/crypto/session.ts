import { fromBase64, toBase64 } from './encoding';

const STORAGE_KEY = 'e1o1:master-key';

/**
 * sessionStorage, per the spec's decision: survives an accidental refresh,
 * does not survive closing the tab, and is never persisted between browser
 * sessions.
 */
export async function storeMasterKey(masterKey: Uint8Array): Promise<void> {
  sessionStorage.setItem(STORAGE_KEY, await toBase64(masterKey));
}

export async function loadMasterKey(): Promise<Uint8Array | null> {
  const stored = sessionStorage.getItem(STORAGE_KEY);
  return stored === null ? null : fromBase64(stored);
}

export function clearMasterKey(): void {
  sessionStorage.removeItem(STORAGE_KEY);
}
