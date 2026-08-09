import { describe, expect, it } from 'vitest';
import { aggregateReport, dateRangeForQuarterPreset, type DecryptedAnketaForReport } from './report';
import type { Goal } from './goals';

function makeGoal(overrides: Partial<Goal> = {}): Goal {
  return {
    id: 'row-1',
    goalUuid: 'goal-uuid-1',
    authorId: 'user-1',
    title: 'Ship the thing',
    description: null,
    targetDate: null,
    status: 'in_progress',
    createdAt: '2026-01-01T00:00:00.000Z',
    ...overrides,
  };
}

describe('dateRangeForQuarterPreset', () => {
  it('spans the 3 months up to now', () => {
    const now = new Date('2026-06-15T00:00:00.000Z');
    const { start, end } = dateRangeForQuarterPreset(now);

    expect(end).toEqual(now);
    expect(start.getUTCMonth()).toBe(2); // March (0-indexed) — June minus 3 months
  });
});

describe('aggregateReport', () => {
  it('collects achievements from both the employee and manager sides', () => {
    const anketas: DecryptedAnketaForReport[] = [
      {
        anketaId: 'a1',
        meetingDate: '2026-01-10T00:00:00.000Z',
        employeeAnswers: {
          achievementEntries: [{ id: 'e1', date: '2026-01-05', text: 'Shipped feature X' }],
          growthEntries: [{ id: 'e2', date: '2026-01-06', text: 'Learned TypeScript generics' }],
        },
        managerAnswers: {
          employeeAchievementEntries: [{ id: 'e3', date: '2026-01-07', text: 'Great incident response' }],
        },
        goals: [],
        checkpoints: [],
      },
    ];

    const result = aggregateReport(anketas);

    expect(result.achievements.map((e) => e.text)).toEqual(['Shipped feature X', 'Great incident response']);
    expect(result.growth.map((e) => e.text)).toEqual(['Learned TypeScript generics']);
  });

  it('skips unpublished sides (null answers) without erroring', () => {
    const anketas: DecryptedAnketaForReport[] = [
      { anketaId: 'a1', meetingDate: '2026-01-10T00:00:00.000Z', employeeAnswers: null, managerAnswers: null, goals: [], checkpoints: [] },
    ];

    expect(aggregateReport(anketas)).toEqual({ achievements: [], growth: [], goals: [] });
  });

  it('sorts achievements and growth chronologically regardless of anketa order', () => {
    const anketas: DecryptedAnketaForReport[] = [
      {
        anketaId: 'a2',
        meetingDate: '2026-03-10T00:00:00.000Z',
        employeeAnswers: { achievementEntries: [{ id: 'later', date: '2026-03-01', text: 'later' }] },
        managerAnswers: null,
        goals: [],
        checkpoints: [],
      },
      {
        anketaId: 'a1',
        meetingDate: '2026-01-10T00:00:00.000Z',
        employeeAnswers: { achievementEntries: [{ id: 'earlier', date: '2026-01-01', text: 'earlier' }] },
        managerAnswers: null,
        goals: [],
        checkpoints: [],
      },
    ];

    expect(aggregateReport(anketas).achievements.map((e) => e.text)).toEqual(['earlier', 'later']);
  });

  it('unions checkpoints for the same goalUuid across two anketas, keeping the latest snapshot', () => {
    const anketas: DecryptedAnketaForReport[] = [
      {
        anketaId: 'a1',
        meetingDate: '2026-01-10T00:00:00.000Z',
        employeeAnswers: null,
        managerAnswers: null,
        goals: [makeGoal({ id: 'row-1', title: 'Original title', createdAt: '2026-01-01T00:00:00.000Z' })],
        checkpoints: [
          { id: 'c1', goalId: 'goal-uuid-1', authorId: 'user-1', text: 'first checkpoint', createdAt: '2026-01-10T00:00:00.000Z' },
        ],
      },
      {
        anketaId: 'a2',
        meetingDate: '2026-02-10T00:00:00.000Z',
        employeeAnswers: null,
        managerAnswers: null,
        // Carried forward: new row id, same goalUuid, updated title, later meetingDate.
        goals: [makeGoal({ id: 'row-2', title: 'Updated title', createdAt: '2026-02-01T00:00:00.000Z' })],
        checkpoints: [
          { id: 'c2', goalId: 'goal-uuid-1', authorId: 'user-1', text: 'second checkpoint', createdAt: '2026-02-10T00:00:00.000Z' },
        ],
      },
    ];

    const result = aggregateReport(anketas);

    expect(result.goals).toHaveLength(1);
    const [goal] = result.goals;
    expect(goal.goalUuid).toBe('goal-uuid-1');
    expect(goal.title).toBe('Updated title'); // latest snapshot wins
    expect(goal.checkpoints.map((c) => c.text)).toEqual(['first checkpoint', 'second checkpoint']); // both survive, in order
  });

  it('picks the latest snapshot by anketa meetingDate even when both goal rows share the same createdAt second', () => {
    // Regression test: the backend's Goal::createdAt is second-precision, so two rows
    // created within the same second (easy to hit — e.g. a fast script) tie under a naive
    // `>` comparison, which then silently keeps the *older* snapshot. This is exactly the
    // bug the real e2e script hit while building this phase.
    const tiedTimestamp = '2026-01-01T00:00:00+00:00';
    const anketas: DecryptedAnketaForReport[] = [
      {
        anketaId: 'a1',
        meetingDate: '2026-01-10T00:00:00.000Z',
        employeeAnswers: null,
        managerAnswers: null,
        goals: [makeGoal({ id: 'row-1', title: 'Original title', createdAt: tiedTimestamp })],
        checkpoints: [],
      },
      {
        anketaId: 'a2',
        meetingDate: '2026-02-10T00:00:00.000Z',
        employeeAnswers: null,
        managerAnswers: null,
        goals: [makeGoal({ id: 'row-2', title: 'Updated title', createdAt: tiedTimestamp })],
        checkpoints: [],
      },
    ];

    expect(aggregateReport(anketas).goals[0].title).toBe('Updated title');
  });

  it('does not attach a checkpoint whose goal never appears in the aggregation set', () => {
    const anketas: DecryptedAnketaForReport[] = [
      {
        anketaId: 'a1',
        meetingDate: '2026-01-10T00:00:00.000Z',
        employeeAnswers: null,
        managerAnswers: null,
        goals: [],
        checkpoints: [{ id: 'c1', goalId: 'orphan-uuid', authorId: 'user-1', text: 'orphan', createdAt: '2026-01-01T00:00:00.000Z' }],
      },
    ];

    expect(aggregateReport(anketas).goals).toEqual([]);
  });
});
