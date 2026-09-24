import { encryptBlob } from '../crypto/anketaKey';
import { decryptDraft } from './drafts';
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
 * (crypto/session.ts — survives a refresh, not a closed tab). The backup is
 * under the draft key, but deriving that needs the private key, which needs
 * the master key — a backup that outlived it would be useless anyway.
 */
export async function saveDraftBackup(
  anketaId: string,
  answers: Answers,
  draftKey: Uint8Array,
): Promise<void> {
  const blob = await encryptBlob(answers, draftKey);
  sessionStorage.setItem(storageKey(anketaId), blob);
}

/**
 * Returns null (never throws) on a missing or undecryptable entry — corrupt,
 * or sealed under a stale key from before a password reset — a best-effort
 * local convenience that should never block or error out the page load.
 */
export async function loadDraftBackup(
  anketaId: string,
  draftKey: Uint8Array,
  legacyMasterKey: Uint8Array | null,
): Promise<{ answers: Answers; legacy: boolean } | null> {
  const blob = sessionStorage.getItem(storageKey(anketaId));
  return blob === null ? null : decryptDraft(blob, draftKey, legacyMasterKey);
}

export function clearDraftBackup(anketaId: string): void {
  sessionStorage.removeItem(storageKey(anketaId));
}
