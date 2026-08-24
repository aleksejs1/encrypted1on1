/**
 * Comments live in one shared blob per anketa (not a DB row each — see the
 * spec's schema-simplicity bullet), writable by either participant, so
 * concurrent writes are handled with optimistic locking: on a 409, the
 * caller re-applies the same mutation (`addComment`/`editComment`/
 * `deleteComment`) to the server's latest state and retries (see
 * `updateComments` in `Anketa.svelte`), rather than merging two
 * independently-computed lists — a merge-by-id would work for pure adds,
 * but the identical pattern is also used for outcomes' edit-in-place
 * `toggleDone`, where merging silently drops the edit.
 */
export interface Comment {
  id: string;
  /** A QuestionField id (questions.ts) or an OutcomeItem id (outcomes.ts). Per-entry comments on list answers are a later phase. */
  targetId: string;
  authorId: string;
  text: string;
  createdAt: string;
}

export function addComment(
  existing: Comment[],
  targetId: string,
  authorId: string,
  text: string,
): Comment[] {
  const comment: Comment = {
    id: crypto.randomUUID(),
    targetId,
    authorId,
    text,
    createdAt: new Date().toISOString(),
  };
  return [...existing, comment];
}

/**
 * Only the server-blob write is shared; "own comment" is enforced here by
 * matching authorId, since the server never inspects the encrypted blob (see
 * the module doc comment above). Throws (rather than silently returning
 * `existing` unchanged) when commentId/authorId don't match anything —
 * `updateField` always re-fetches the blob fresh before calling this, so a
 * miss means the comment was deleted elsewhere between opening the edit/
 * delete UI and submitting it; the caller needs to know that happened
 * instead of the save silently succeeding as a no-op.
 */
export function editComment(
  existing: Comment[],
  commentId: string,
  authorId: string,
  text: string,
): Comment[] {
  const index = existing.findIndex(
    (c) => c.id === commentId && c.authorId === authorId,
  );
  if (index === -1) {
    throw new Error(
      `editComment: no comment ${commentId} by ${authorId} (deleted elsewhere?)`,
    );
  }
  const updated = [...existing];
  updated[index] = { ...updated[index], text };
  return updated;
}

export function deleteComment(
  existing: Comment[],
  commentId: string,
  authorId: string,
): Comment[] {
  const filtered = existing.filter(
    (c) => !(c.id === commentId && c.authorId === authorId),
  );
  if (filtered.length === existing.length) {
    throw new Error(
      `deleteComment: no comment ${commentId} by ${authorId} (deleted elsewhere already?)`,
    );
  }
  return filtered;
}
