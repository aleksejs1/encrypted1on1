import type { CheckpointStatusTag, GoalCheckpoint } from './goals';

/**
 * Explicit worst-to-best scale — CheckpointStatusTag's own declared union
 * order ('on_track' | 'at_risk' | 'blocked', matching Anketa.svelte's
 * CHECKPOINT_STATUS_TAG_KEYS object order) reads best-to-worst instead, the
 * opposite direction from questions.ts's radio-field options arrays that
 * extractTrendValues (moodWorkloadTrend.ts) reuses directly as its scale —
 * reusing that same trick unmodified here would plot "blocked" above
 * "on_track" on the sparkline, backwards.
 */
const STATUS_TAG_ORDER: CheckpointStatusTag[] = [
  'blocked',
  'at_risk',
  'on_track',
];

export const GOAL_PROGRESS_MAX_INDEX = STATUS_TAG_ORDER.length - 1;

/**
 * Checkpoints without a statusTag are skipped, not zero-filled — same
 * "a gap in the data shouldn't silently read as bad" rule extractTrendValues
 * already established for mood/workload; a checkpoint's status label is
 * genuinely optional (a text-only progress note contributes no point here).
 */
export function extractGoalProgressValues(
  checkpoints: GoalCheckpoint[],
): number[] {
  const values: number[] = [];
  for (const checkpoint of checkpoints) {
    if (!checkpoint.statusTag) continue;
    const index = STATUS_TAG_ORDER.indexOf(checkpoint.statusTag);
    if (index !== -1) values.push(index);
  }
  return values;
}
