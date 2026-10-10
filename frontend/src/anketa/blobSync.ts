import { ApiError } from '../api/client';
import { decryptBlob, encryptBlob } from '../crypto/anketaKey';

export interface BlobSyncPayload {
  blob: string | null;
  version: number;
}

/**
 * Shared shape of Anketa.svelte's three optimistic-concurrency blobs
 * (comments, outcomes, goal checkpoints — see comments.ts's module doc for
 * why a retry, not a merge, is the right conflict strategy for all three):
 * decrypt the current blob, apply the caller's mutation, encrypt, and save.
 * If `save` rejects with the server's conflict response, retry once against
 * whatever `onConflict` extracts from it — no extra round-trip, since the
 * 409 body already carries the server's latest state.
 */
export async function updateBlobWithRetry<T>(
  anketaKey: Uint8Array,
  initial: BlobSyncPayload,
  apply: (current: T) => T,
  save: (blob: string, expectedVersion: number) => Promise<void>,
  onConflict: (error: unknown) => BlobSyncPayload | undefined,
): Promise<T> {
  async function attempt(payload: BlobSyncPayload): Promise<T> {
    const current = payload.blob
      ? (await decryptBlob<T>(payload.blob, anketaKey)).data
      : ([] as unknown as T);
    const items = apply(current);
    const blob = await encryptBlob(items, anketaKey);
    await save(blob, payload.version);
    return items;
  }

  try {
    return await attempt(initial);
  } catch (error) {
    const conflict = onConflict(error);
    if (!conflict) throw error;
    return await attempt(conflict);
  }
}

/**
 * A stale-version 409's body: the server's current blob and version under
 * the endpoint's own field names (`commentsBlob`/`commentsVersion`, …). Null
 * for anything else, e.g. the "archived" 409, which carries neither and is
 * not something to retry against.
 */
export function versionConflictBody(
  error: unknown,
  blobKey: string,
  versionKey: string,
): BlobSyncPayload | null {
  if (!(error instanceof ApiError) || error.status !== 409) return null;
  const body = error.body as Record<string, unknown> | null;
  const version = body?.[versionKey];
  if (typeof version !== 'number') return null;
  const blob = body?.[blobKey];
  return { blob: typeof blob === 'string' ? blob : null, version };
}

/**
 * Carry-forward for a shared list (outcomes, topics): the items `keep`
 * accepts, encrypted with a new anketa's key. Returns undefined when there's
 * nothing to carry, so callers can omit the field entirely rather than send
 * an empty-array blob.
 */
export async function carryForwardItems<T>(
  items: T[],
  newKey: Uint8Array,
  keep: (item: T) => boolean,
): Promise<string | undefined> {
  const kept = items.filter(keep);
  return kept.length === 0 ? undefined : encryptBlob(kept, newKey);
}

/** The same from a prior anketa's blob, decrypted with its own key. */
export async function carryForwardBlob<T>(
  blob: string | null,
  oldKey: Uint8Array,
  newKey: Uint8Array,
  keep: (item: T) => boolean,
): Promise<string | undefined> {
  if (!blob) return undefined;

  const envelope = await decryptBlob<T[]>(blob, oldKey);
  return carryForwardItems(envelope.data, newKey, keep);
}
