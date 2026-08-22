import type { Answers, AnswerValue, ListEntry } from './questions';
import type { Goal, GoalCheckpoint } from './goals';

/**
 * "Отчёт за период": pure aggregation over data the caller has *already*
 * decrypted (Report.svelte does the fetching/decrypting — this module never
 * touches the network or crypto, so it's trivially unit-testable). The
 * caller is also responsible for deciding which anketas belong in the
 * aggregation set (date range, employee/manager access) — this module just
 * combines whatever it's handed.
 */
export interface DecryptedAnketaForReport {
  anketaId: string;
  /** Used to order goal snapshots chronologically (see aggregateReport) — not for filtering; the caller already decided which anketas belong in range. */
  meetingDate: string;
  /** Employee's own answers, decrypted — null if not yet published (not viewable, same rule as Anketa.svelte). */
  employeeAnswers: Answers | null;
  /** Manager's answers, decrypted — null if not yet published. */
  managerAnswers: Answers | null;
  /** This anketa's own goal snapshots (Phase 6c: a fresh row per anketa, sharing `goalUuid` across cycles). */
  goals: Goal[];
  /** This anketa's own checkpoints, keyed by `goalUuid` (see Anketa.svelte's handleAddCheckpoint). */
  checkpoints: GoalCheckpoint[];
}

interface ReportGoal extends Goal {
  /** Every checkpoint for this `goalUuid`, unioned across all anketas in the aggregation set, chronological. */
  checkpoints: GoalCheckpoint[];
}

export interface ReportData {
  achievements: ListEntry[];
  growth: ListEntry[];
  goals: ReportGoal[];
}

/** "Quarter" preset = the last 3 months rolling from now, not a calendar-quarter boundary (see the Phase 6f plan). `now` is a parameter so this is deterministically testable. */
export function dateRangeForQuarterPreset(now: Date = new Date()): {
  start: Date;
  end: Date;
} {
  const start = new Date(now);
  start.setMonth(start.getMonth() - 3);
  return { start, end: now };
}

/**
 * Combines achievements/growth entries and goal-with-checkpoint-history across
 * the given (already date-range-filtered) anketas. Goals are deduped by
 * `goalUuid`: the latest snapshot's title/description/status/targetDate wins,
 * but checkpoints are unioned from every occurrence — that's the whole point
 * of carrying `goalUuid` through carry-forward (see the Phase 6c plan).
 *
 * "Latest" is decided by processing anketas sorted by `meetingDate`, not by
 * comparing each goal row's own `createdAt` — the backend's `createdAt` is
 * second-precision, so two rows created in the same second (easy to hit, e.g.
 * scripted creation) tie, and a naive `>` comparison then keeps whichever was
 * processed first, silently the wrong one. Sorting by the anketas' own
 * `meetingDate` first and then just letting each later occurrence overwrite
 * the previous one sidesteps the tie entirely instead of trying to break it.
 */
export function aggregateReport(
  anketas: DecryptedAnketaForReport[],
): ReportData {
  const achievements: ListEntry[] = [];
  const growth: ListEntry[] = [];
  const goalsByUuid = new Map<string, ReportGoal>();

  const sorted = [...anketas].sort((a, b) =>
    a.meetingDate.localeCompare(b.meetingDate),
  );

  for (const anketa of sorted) {
    if (anketa.employeeAnswers) {
      achievements.push(
        ...asListEntries(anketa.employeeAnswers.achievementEntries),
      );
      growth.push(...asListEntries(anketa.employeeAnswers.growthEntries));
    }
    if (anketa.managerAnswers) {
      achievements.push(
        ...asListEntries(anketa.managerAnswers.employeeAchievementEntries),
      );
    }

    // Goals before checkpoints, per anketa: a checkpoint's goal always has its own
    // snapshot row in the same anketa (see the Phase 6f plan), so this ordering
    // guarantees goalsByUuid already has an entry by the time its checkpoints arrive.
    // Anketas are processed oldest-first (see above), so each occurrence of a
    // goalUuid simply overwrites the previous snapshot — no timestamp comparison.
    for (const goal of anketa.goals) {
      const existing = goalsByUuid.get(goal.goalUuid);
      goalsByUuid.set(goal.goalUuid, {
        ...goal,
        checkpoints: existing?.checkpoints ?? [],
      });
    }
    for (const checkpoint of anketa.checkpoints) {
      goalsByUuid.get(checkpoint.goalId)?.checkpoints.push(checkpoint);
    }
  }

  achievements.sort(byDate);
  growth.sort(byDate);
  const goals = [...goalsByUuid.values()].sort((a, b) =>
    a.createdAt.localeCompare(b.createdAt),
  );
  for (const goal of goals) {
    goal.checkpoints.sort((a, b) => a.createdAt.localeCompare(b.createdAt));
  }

  return { achievements, growth, goals };
}

function byDate(a: ListEntry, b: ListEntry): number {
  return a.date.localeCompare(b.date);
}

/** achievementEntries/growthEntries/employeeAchievementEntries are always `type: 'list'` fields (questions.ts) — always ListEntry[] when present, never the other AnswerValue shapes. */
function asListEntries(value: AnswerValue): ListEntry[] {
  return Array.isArray(value) ? (value as ListEntry[]) : [];
}
