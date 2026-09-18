/**
 * Removes ids that no longer exist after a wholesale list replace —
 * otherwise a stale `true` left over from an item deleted elsewhere (self
 * or the counterpart) would permanently block anyCommentThreadBusy-gated
 * live refresh for the rest of the session, since nothing else ever prunes
 * this record.
 *
 * `commentThreadsBusy` is one shared record spanning several different id
 * namespaces at once (question-field ids, outcome ids, goal ids,
 * checkpoint ids), so this only ever clears ids the caller explicitly says
 * it owned *before* its own replace (`previousIds`) and no longer does
 * (`remainingIds`) — never anything else already `true` in the record. An
 * earlier version scanned the whole record for "true but not in
 * remainingIds," which looked right in isolation but actually cleared any
 * *other* namespace's busy id too (e.g. an outcome-scoped call wiping out
 * a field's own open comment edit) purely because that id wasn't an
 * outcome id — exactly the kind of shared-function-behavior-not-re-derived-
 * per-caller bug CLAUDE.md's working-style section calls out from the
 * multi-tab-unlock incident.
 *
 * Shared between Anketa.svelte's own live-update poll and AnketaOutcomes'
 * delete handler — both wholesale-replace a list this record has ids from.
 */
export function pruneStaleBusyEntries(
  record: Record<string, boolean>,
  previousIds: string[],
  remainingIds: Set<string>,
): Record<string, boolean> {
  const removed = previousIds.filter((id) => !remainingIds.has(id));
  if (removed.length === 0) return record;
  const next = { ...record };
  for (const removedId of removed) next[removedId] = false;
  return next;
}
