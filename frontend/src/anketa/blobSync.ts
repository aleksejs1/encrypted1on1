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
