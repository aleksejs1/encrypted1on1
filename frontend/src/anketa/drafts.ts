import { ApiError } from '../api/client';
import { decryptBlob, encryptBlob } from '../crypto/anketaKey';
import type { Answers } from './questions';

/**
 * Decrypts this side's unpublished draft (see crypto/keypair.ts's
 * deriveDraftKey()). Falls back to the master key for a draft saved before
 * drafts moved off it — `legacy: true` tells the caller to re-save it under
 * the draft key. Returns null instead of throwing when neither key opens it
 * (saved before a forgotten-password reset, or a pre-move draft left unopened
 * through a password change): a lost draft must not take the whole anketa
 * page, or the data export, down with it — GitHub issue #129.
 */
export async function decryptDraft(
  blob: string,
  draftKey: Uint8Array,
  legacyMasterKey: Uint8Array | null,
): Promise<{ answers: Answers; legacy: boolean } | null> {
  const answers = await decryptOrNull(blob, draftKey);
  if (answers) return { answers, legacy: false };
  const legacyAnswers = legacyMasterKey
    ? await decryptOrNull(blob, legacyMasterKey)
    : null;
  return legacyAnswers ? { answers: legacyAnswers, legacy: true } : null;
}

/** Wrong key or corrupt blob → null. */
async function decryptOrNull(
  blob: string,
  key: Uint8Array,
): Promise<Answers | null> {
  try {
    return (await decryptBlob<Answers>(blob, key)).data;
  } catch {
    return null;
  }
}

/**
 * Whether any field has content — the blank form a page shows in place of an
 * undecryptable draft has none, so autosaving it would only destroy the
 * stored ciphertext without the user having typed anything.
 */
export function hasAnyAnswer(answers: Answers): boolean {
  return Object.values(answers).some(
    (value) => value !== undefined && value.length > 0,
  );
}

/**
 * Moves every draft still under the master key (from before drafts moved off
 * it) to the draft key, for the in-app password change to run *before* it
 * re-wraps the private key: afterwards that master key is gone, and a legacy
 * draft nobody had reopened would be stranded. Safe to do ahead of the change,
 * since the draft key doesn't depend on the password. Drafts already under the
 * draft key, or that neither key opens, are left alone. A 403/404/409 on a save
 * means access was lost, the side published or the anketa archived in the
 * meantime — no draft of ours left there — and is skipped; any other failure
 * throws, so the caller can leave the password (and the master key that still
 * opens anything not yet moved) unchanged.
 */
export async function migrateLegacyDrafts(
  drafts: { anketaId: string; blob: string }[],
  draftKey: Uint8Array,
  masterKey: Uint8Array,
  save: (anketaId: string, blob: string) => Promise<unknown>,
): Promise<void> {
  for (const { anketaId, blob } of drafts) {
    const draft = await decryptDraft(blob, draftKey, masterKey);
    if (!draft?.legacy) continue;
    try {
      await save(anketaId, await encryptBlob(draft.answers, draftKey));
    } catch (error) {
      const skippable =
        error instanceof ApiError && [403, 404, 409].includes(error.status);
      if (!skippable) throw error;
    }
  }
}
