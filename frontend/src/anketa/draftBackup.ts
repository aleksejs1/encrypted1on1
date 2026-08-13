import { decryptBlob, encryptBlob } from '../crypto/anketaKey';
import type { Answers } from './questions';

function storageKey(anketaId: string): string {
  return `e1o1:draft-backup:${anketaId}`;
}

/**
 * A local safety net for unpublished anketa answers, independent of the
 * debounced server autosave (Anketa.svelte's scheduleSave/saveDraft) —
 * protects against losing an edit if the server sync itself silently fails
 * (a network blip), not just against the debounce's own timing. sessionStorage,
 * not localStorage: matches the master key's own storage lifetime exactly
 * (crypto/session.ts — survives a refresh, not a closed tab), since a backup
 * that outlived the master key needed to decrypt it would be useless anyway.
 */
export async function saveDraftBackup(
  anketaId: string,
  answers: Answers,
  masterKey: Uint8Array,
): Promise<void> {
  const blob = await encryptBlob(answers, masterKey);
  sessionStorage.setItem(storageKey(anketaId), blob);
}

/**
 * Returns null (never throws) on a missing or undecryptable entry — corrupt,
 * or sealed under a stale key from before a password reset — a best-effort
 * local convenience that should never block or error out the page load.
 */
export async function loadDraftBackup(
  anketaId: string,
  masterKey: Uint8Array,
): Promise<Answers | null> {
  const blob = sessionStorage.getItem(storageKey(anketaId));
  if (blob === null) return null;
  try {
    const envelope = await decryptBlob<Answers>(blob, masterKey);
    return envelope.data;
  } catch {
    return null;
  }
}

export function clearDraftBackup(anketaId: string): void {
  sessionStorage.removeItem(storageKey(anketaId));
}
