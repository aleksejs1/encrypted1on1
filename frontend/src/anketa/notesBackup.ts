import { decryptBlob, encryptBlob } from '../crypto/anketaKey';
import type { NotesBackup } from './notesState';

/**
 * A tab-local backup of private notes (GitHub issue #132 §6.4), so text typed
 * in this tab survives a logout or session expiry followed by logging back in
 * here, a refresh, a failed save, or a save whose response never arrived.
 *
 * sessionStorage, like draftBackup.ts: survives a refresh, not a closed tab.
 * Encrypted under a key derived from the private key
 * (crypto/privateNotes.ts's deriveNotesBackupKey()): the same after logging
 * back in, unchanged by an in-app password change, and available before the
 * first save. A backup can be the only copy of unsaved text (a panel destroyed
 * with a failed save), so it mustn't be stranded by a password change.
 */
function storageKey(userId: string, anketaId: string): string {
  return `${userPrefix(userId)}${anketaId}`;
}

const KEY_PREFIX = 'e1o1:notes-backup:';

function userPrefix(userId: string): string {
  return `${KEY_PREFIX}${userId}:`;
}

/** Storage keys of notes backups, this user's or (null) anyone's. */
function backupKeys(userId: string | null): string[] {
  const prefix = userId === null ? KEY_PREFIX : userPrefix(userId);
  const keys: string[] = [];
  try {
    for (let i = 0; i < sessionStorage.length; i++) {
      const key = sessionStorage.key(i);
      if (key?.startsWith(prefix)) keys.push(key);
    }
  } catch {
    // Storage unavailable: nothing to find.
  }
  return keys;
}

/**
 * Whether this user (or, with null, anyone) has a notes backup in this tab,
 * i.e. unsaved notes somewhere.
 */
export function hasNotesBackups(userId: string | null): boolean {
  return backupKeys(userId).length > 0;
}

/**
 * Removes this user's backups that no longer open: under the keypair from
 * before a forgotten-password reset, they can never be restored, and the
 * anketa page that would read and remove them may not open at all until the
 * counterpart re-shares. Run on unlock (App.svelte), so they can't keep the
 * unload warning on for good.
 */
export async function discardUnopenableNotesBackups(
  userId: string,
  backupKey: Uint8Array,
): Promise<void> {
  for (const key of backupKeys(userId)) {
    try {
      const blob = sessionStorage.getItem(key);
      if (blob !== null) await decryptBlob<unknown>(blob, backupKey);
    } catch {
      try {
        sessionStorage.removeItem(key);
      } catch {
        // Best effort.
      }
    }
  }
}

/** Never throws: a failed backup write (a full storage quota, say) must never stop saving to the server. */
export async function writeNotesBackup(
  userId: string,
  anketaId: string,
  backup: NotesBackup,
  backupKey: Uint8Array,
  /** Checked after encrypting, right before storing: false skips the write. */
  stillOwner: () => boolean = () => true,
): Promise<void> {
  try {
    const blob = await encryptBlob(backup, backupKey);
    if (!stillOwner()) return;
    sessionStorage.setItem(storageKey(userId, anketaId), blob);
  } catch {
    // Best effort.
  }
}

function isBackup(value: unknown): value is NotesBackup {
  if (typeof value !== 'object' || value === null) return false;
  const candidate = value as Record<string, unknown>;
  return (
    typeof candidate.baseVersion === 'number' &&
    typeof candidate.text === 'string' &&
    Array.isArray(candidate.sentTexts) &&
    candidate.sentTexts.every((sent) => typeof sent === 'string')
  );
}

/**
 * null when there's none, or it can't be opened (another key, corruption): the
 * server alone then. One that can't be opened is removed: it's under an old
 * keypair (a forgotten-password reset) and can never be restored, so it
 * mustn't keep the unload warning on (notesUnloadWarning.ts).
 */
export async function readNotesBackup(
  userId: string,
  anketaId: string,
  backupKey: Uint8Array,
): Promise<NotesBackup | null> {
  try {
    const blob = sessionStorage.getItem(storageKey(userId, anketaId));
    if (blob === null) return null;
    const { data } = await decryptBlob<unknown>(blob, backupKey);
    if (isBackup(data)) return data;
  } catch {
    // Unopenable: removed below.
  }
  clearNotesBackup(userId, anketaId);
  return null;
}

export function clearNotesBackup(userId: string, anketaId: string): void {
  try {
    sessionStorage.removeItem(storageKey(userId, anketaId));
  } catch {
    // Best effort.
  }
}
