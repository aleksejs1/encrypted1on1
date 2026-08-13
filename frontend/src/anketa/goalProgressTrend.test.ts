import { describe, expect, it } from 'vitest';
import {
  extractGoalProgressValues,
  GOAL_PROGRESS_MAX_INDEX,
} from './goalProgressTrend';
import type { GoalCheckpoint } from './goals';

function checkpoint(partial: Partial<GoalCheckpoint>): GoalCheckpoint {
  return {
    id: 'c1',
    goalId: 'goal-1',
    authorId: 'user-1',
    createdAt: '2026-01-01T00:00:00Z',
    ...partial,
  };
}

describe('extractGoalProgressValues', () => {
  it('maps known status tags to a worst-to-best index', () => {
    expect(
      extractGoalProgressValues([
        checkpoint({ statusTag: 'blocked' }),
        checkpoint({ statusTag: 'at_risk' }),
        checkpoint({ statusTag: 'on_track' }),
      ]),
    ).toEqual([0, 1, 2]);
  });

  it('places "blocked" at the bottom and "on_track" at the top of the scale', () => {
    expect(
      extractGoalProgressValues([checkpoint({ statusTag: 'blocked' })]),
    ).toEqual([0]);
    expect(
      extractGoalProgressValues([checkpoint({ statusTag: 'on_track' })]),
    ).toEqual([GOAL_PROGRESS_MAX_INDEX]);
  });

  it('skips checkpoints with no statusTag, rather than zero-filling', () => {
    expect(
      extractGoalProgressValues([
        checkpoint({ statusTag: 'on_track' }),
        checkpoint({ text: 'just a note, no status' }),
        checkpoint({ statusTag: 'blocked' }),
      ]),
    ).toEqual([2, 0]);
  });

  it('returns an empty array for no checkpoints', () => {
    expect(extractGoalProgressValues([])).toEqual([]);
  });
});
