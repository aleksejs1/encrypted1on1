/**
 * "You" for the current viewer's own items, otherwise the counterpart's
 * short name — shared by AnketaOutcomes' and AnketaGoals' own author tags
 * (previously one function in Anketa.svelte; split into two identical
 * copies by issue #64's decomposition, then consolidated back here once
 * review caught the drift risk). `youLabel` is passed in rather than a
 * translation function, so this stays a plain, i18n-library-agnostic
 * lookup — callers resolve `$_('anketa.you')` themselves.
 */
export function resolveAuthorLabel(
  authorId: string,
  myUserId: string,
  authorNames: Record<string, string>,
  youLabel: string,
): string {
  return authorId === myUserId ? youLabel : (authorNames[authorId] ?? authorId);
}
