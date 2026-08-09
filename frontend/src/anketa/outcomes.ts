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

export function addOutcome(existing: OutcomeItem[], authorId: string, text: string): OutcomeItem[] {
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
  return existing.map((item) => (item.id === id ? { ...item, done: !item.done } : item));
}
