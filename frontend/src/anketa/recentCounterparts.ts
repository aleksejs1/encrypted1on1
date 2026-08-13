import { groupByCounterpart } from './groupByCounterpart';

interface UserSummary {
  id: string;
  email: string;
  publicKey: string;
}

interface PriorAnketaSummary {
  counterpartId: string;
  counterpartEmail: string;
  meetingDate: string;
}

/**
 * Reorders `users` so counterparts the caller already has anketa history
 * with come first, most-recent-anketa-first, then everyone else keeps
 * their original (unranked) relative order — per the spec's "recent
 * counterparts at the top of the typeahead" requirement. `priorAnketas` is
 * assumed already meetingDate DESC-sorted (matches GET /api/anketas), the
 * same assumption groupByCounterpart() already makes.
 */
export function sortByRecentCounterparts(users: UserSummary[], priorAnketas: PriorAnketaSummary[]): UserSummary[] {
  const recentIds = groupByCounterpart(priorAnketas).map((group) => group.counterpartId);
  const recentIndex = new Map(recentIds.map((id, i) => [id, i]));

  return [...users].sort((a, b) => {
    const rankA = recentIndex.get(a.id) ?? Infinity;
    const rankB = recentIndex.get(b.id) ?? Infinity;
    return rankA - rankB;
  });
}
