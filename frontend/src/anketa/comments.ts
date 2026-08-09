/**
 * Comments live in one shared blob per anketa (not a DB row each — see the
 * spec's schema-simplicity bullet), writable by either participant, so
 * concurrent writes are handled with optimistic locking: on a 409, the
 * caller re-applies `addComment` to the server's latest state and retries
 * (see `updateComments` in `Anketa.svelte`), rather than merging two
 * independently-computed lists — a merge-by-id would work for pure adds
 * like this one, but the identical pattern is also used for outcomes'
 * edit-in-place `toggleDone`, where merging silently drops the edit.
 */
export interface Comment {
  id: string;
  /** A QuestionField id (questions.ts) or an OutcomeItem id (outcomes.ts). Per-entry comments on list answers are a later phase. */
  targetId: string;
  authorId: string;
  text: string;
  createdAt: string;
}

export function addComment(existing: Comment[], targetId: string, authorId: string, text: string): Comment[] {
  const comment: Comment = {
    id: crypto.randomUUID(),
    targetId,
    authorId,
    text,
    createdAt: new Date().toISOString(),
  };
  return [...existing, comment];
}
