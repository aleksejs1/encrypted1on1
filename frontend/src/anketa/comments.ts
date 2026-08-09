/**
 * Comments live in one shared blob per anketa (not a DB row each — see the
 * spec's schema-simplicity bullet), writable by either participant, so
 * concurrent writes are handled with optimistic locking + a grow-only-set
 * merge by id — see the Phase 6a plan for why a union-by-id merge is safe
 * here (comments are only ever added, never edited by someone else).
 */
export interface Comment {
  id: string;
  /** A QuestionField id — see questions.ts. Per-entry comments are a later phase. */
  targetId: string;
  authorId: string;
  text: string;
  createdAt: string;
}

export function mergeComments(local: Comment[], remote: Comment[]): Comment[] {
  const byId = new Map(remote.map((comment) => [comment.id, comment]));
  for (const comment of local) {
    if (!byId.has(comment.id)) {
      byId.set(comment.id, comment);
    }
  }
  return [...byId.values()].sort((a, b) => a.createdAt.localeCompare(b.createdAt));
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
