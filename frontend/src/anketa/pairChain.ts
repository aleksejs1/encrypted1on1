import type { AnketaSummary } from '../api/types';
import type { Side } from './questions';

type PairAnketaSummary = Pick<
  AnketaSummary,
  | 'id'
  | 'counterpartId'
  | 'meetingDate'
  | 'archivedAt'
  | 'oneOff'
  | 'periodicityDays'
>;

export interface PairChainState<T extends PairAnketaSummary> {
  /** The pair's most recent archived chain anketa — the carry-forward source. */
  previousAnketa: T | undefined;
  /** The pair's open chain anketa — if any, a new anketa is created as a one-off. */
  openAnketa: T | undefined;
  /** What the server will inherit, or null when it has nothing and needs asking. */
  inheritedPeriodicityDays: number | null;
}

/** Earliest first; UUIDv7 ids sort by creation time. */
function byMeetingDateThenId(
  a: Pick<AnketaSummary, 'id' | 'meetingDate'>,
  b: Pick<AnketaSummary, 'id' | 'meetingDate'>,
): number {
  return (
    Date.parse(a.meetingDate) - Date.parse(b.meetingDate) ||
    (a.id < b.id ? -1 : a.id > b.id ? 1 : 0)
  );
}

/**
 * The client-side mirror of AnketaController::create()'s pair lookups
 * (AnketaRepository::findMostRecentArchivedForPair()/findOpenForPair() and
 * the periodicity fallback between them), used by CreateAnketa.svelte only
 * to decide what to show and send up front — the server decides for itself
 * on submit. Both skip one-off anketas, which sit outside the pair's chain
 * (GitHub issue #111, see docs/decisions/2026-09-23-one-open-anketa-chain-per-pair.md).
 * A "pair" is just the counterpart here, since every row in the caller's own
 * list already involves the caller.
 */
export function pairChainState<T extends PairAnketaSummary>(
  priorAnketas: T[],
  counterpartId: string,
): PairChainState<T> {
  // Earliest first, by meetingDate then id — the same order the server's queries
  // use (UUIDv7 ids sort by creation time), sorted explicitly rather than trusting
  // the list's own tie order.
  const chain = priorAnketas
    .filter((a) => a.counterpartId === counterpartId && !a.oneOff)
    .sort(byMeetingDateThenId);
  // The latest archived one (findMostRecentArchivedForPair()), and the earliest open
  // one (findOpenForPair() — only ambiguous for a pair that forked before issue #111's fix).
  const previousAnketa = chain.findLast((a) => a.archivedAt !== null);
  const openAnketa = chain.find((a) => a.archivedAt === null);

  return {
    previousAnketa,
    openAnketa,
    inheritedPeriodicityDays:
      previousAnketa?.periodicityDays ?? openAnketa?.periodicityDays ?? null,
  };
}

/** Where a pair's permanent link (GitHub issue #203) leads. */
export type PairMeeting<T extends PairAnketaSummary> =
  /** The pair's open chain meeting: the link opens it. */
  | { kind: 'open'; anketa: T }
  /** No open one: the last closed meeting, with an offer to schedule the next. */
  | { kind: 'closed'; anketa: T }
  /** No chain meeting with this person: an unknown user, or nothing shared yet. */
  | { kind: 'none' };

/**
 * The meeting a pair's permanent link stands for: the open chain meeting,
 * else the most recently closed one. One-offs are never it, the same as in
 * pairChainState(), and so is my role: a pair has one chain whichever of the
 * two leads it.
 */
export function pairMeeting<T extends PairAnketaSummary>(
  anketas: T[],
  counterpartId: string,
): PairMeeting<T> {
  const { openAnketa, previousAnketa } = pairChainState(anketas, counterpartId);
  if (openAnketa) return { kind: 'open', anketa: openAnketa };
  if (previousAnketa) return { kind: 'closed', anketa: previousAnketa };
  return { kind: 'none' };
}

/**
 * My counterpart in a pair's permanent link, which names both people so that
 * either can use it: the id that isn't mine. Null for a link to a pair I'm
 * not part of.
 */
export function pairCounterpartId(
  userIds: readonly [string, string],
  myUserId: string,
): string | null {
  const [a, b] = userIds;
  if (a === b) return null;
  if (a === myUserId) return b;
  if (b === myUserId) return a;
  return null;
}

/**
 * My role in the pair's most recent meeting, open or archived, or null for a
 * new pair. One-offs count too, so after the pair swapped roles for its next
 * 1:1 (GitHub issue #254) a later-dated one-off can still state the old ones.
 * Shown on the create form as a fact about the pair (GitHub issue #252),
 * never used to select a role (#251).
 */
export function pairRole(
  priorAnketas: Pick<
    AnketaSummary,
    'id' | 'counterpartId' | 'meetingDate' | 'myRole'
  >[],
  counterpartId: string,
): Side | null {
  const latest = priorAnketas
    .filter((a) => a.counterpartId === counterpartId)
    .sort(byMeetingDateThenId)
    .at(-1);
  return latest?.myRole ?? null;
}
