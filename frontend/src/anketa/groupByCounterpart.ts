export interface CounterpartGroup<
  T extends { counterpartId: string; counterpartEmail: string },
> {
  counterpartId: string;
  counterpartEmail: string;
  anketas: T[];
}

/**
 * Groups an already meetingDate-DESC-sorted anketa list (see
 * AnketaController::list()) by counterpart. No explicit group ordering is
 * needed: each counterpart's first appearance in that order is their most
 * recent anketa, and Map preserves insertion order, so groups come out
 * ordered by most-recent-activity-first for free.
 */
export function groupByCounterpart<
  T extends { counterpartId: string; counterpartEmail: string },
>(anketas: T[]): CounterpartGroup<T>[] {
  const groups = new Map<string, CounterpartGroup<T>>();
  for (const anketa of anketas) {
    let group = groups.get(anketa.counterpartId);
    if (!group) {
      group = {
        counterpartId: anketa.counterpartId,
        counterpartEmail: anketa.counterpartEmail,
        anketas: [],
      };
      groups.set(anketa.counterpartId, group);
    }
    group.anketas.push(anketa);
  }
  return [...groups.values()];
}
