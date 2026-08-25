import { decryptBlob, encryptBlob } from '../crypto/anketaKey';

/**
 * "Итоги встречи": tactical agreed action items, jointly visible, no draft
 * phase (see the spec). Ownership convention — only the author toggles
 * `done` or edits their own item — is enforced in the UI, not the server,
 * same reasoning as comments and the rest of this system's E2E model.
 * Concurrent writes: see the comment in comments.ts — `toggleDone` edits an
 * existing item in place, so conflicts are resolved by re-applying it to
 * the server's latest state on a 409, not by merging two computed lists.
 */
export interface OutcomeItem {
  id: string;
  authorId: string;
  text: string;
  done: boolean;
  createdAt: string;
}

export function addOutcome(
  existing: OutcomeItem[],
  authorId: string,
  text: string,
): OutcomeItem[] {
  const item: OutcomeItem = {
    id: crypto.randomUUID(),
    authorId,
    text,
    done: false,
    createdAt: new Date().toISOString(),
  };
  return [...existing, item];
}

export function toggleDone(existing: OutcomeItem[], id: string): OutcomeItem[] {
  return existing.map((item) =>
    item.id === id ? { ...item, done: !item.done } : item,
  );
}

/**
 * Only the server-blob write is shared; "own item" is enforced here by
 * matching authorId, mirroring editComment/deleteComment in comments.ts —
 * same reasoning for throwing on a miss instead of returning `existing`
 * unchanged (the caller re-fetches the blob fresh before calling this, so a
 * miss means the item was deleted elsewhere in between).
 */
export function editOutcome(
  existing: OutcomeItem[],
  id: string,
  authorId: string,
  text: string,
): OutcomeItem[] {
  const index = existing.findIndex(
    (item) => item.id === id && item.authorId === authorId,
  );
  if (index === -1) {
    throw new Error(
      `editOutcome: no item ${id} by ${authorId} (deleted elsewhere?)`,
    );
  }
  const updated = [...existing];
  updated[index] = { ...updated[index], text };
  return updated;
}

/**
 * Deleting an item leaves any comments targeting it orphaned in
 * commentsBlob (comments.ts has no notion of the outcomes list, and the two
 * blobs have independent optimistic-concurrency versions, so cleaning them
 * up together isn't a single atomic write) — those comments simply stop
 * being reachable, same as goal checkpoints have no delete at all yet.
 */
export function deleteOutcome(
  existing: OutcomeItem[],
  id: string,
  authorId: string,
): OutcomeItem[] {
  const filtered = existing.filter(
    (item) => !(item.id === id && item.authorId === authorId),
  );
  if (filtered.length === existing.length) {
    throw new Error(
      `deleteOutcome: no item ${id} by ${authorId} (deleted elsewhere already?)`,
    );
  }
  return filtered;
}

/**
 * Decrypts a prior anketa's outcomesBlob with its own key, keeps only the items still
 * unchecked, and re-encrypts them with a new anketa's key — used both when manually
 * creating a new anketa for an existing pair (CreateAnketa.svelte) and when archiving
 * auto-recreates the next one (Anketa.svelte's archive flow, Phase 6d). Returns
 * undefined when there's nothing to carry, so callers can omit the field entirely
 * rather than send an empty-array blob.
 */
export async function carryForwardOutcomes(
  blob: string | null,
  oldKey: Uint8Array,
  newKey: Uint8Array,
): Promise<string | undefined> {
  if (!blob) return undefined;

  const envelope = await decryptBlob<OutcomeItem[]>(blob, oldKey);
  const unchecked = envelope.data.filter((item) => !item.done);
  if (unchecked.length === 0) return undefined;

  return encryptBlob(unchecked, newKey);
}
