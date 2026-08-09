import { describe, expect, it } from 'vitest';
import { addCheckpoint } from './goals';

describe('addCheckpoint', () => {
  it('appends a checkpoint with text only', () => {
    const result = addCheckpoint([], 'goal-1', 'user-1', 'made good progress this week');

    expect(result).toHaveLength(1);
    expect(result[0].goalId).toBe('goal-1');
    expect(result[0].authorId).toBe('user-1');
    expect(result[0].text).toBe('made good progress this week');
    expect(result[0].statusTag).toBeUndefined();
  });

  it('appends a checkpoint with a status tag only, no text required', () => {
    const result = addCheckpoint([], 'goal-1', 'user-1', undefined, 'on_track');

    expect(result).toHaveLength(1);
    expect(result[0].text).toBeUndefined();
    expect(result[0].statusTag).toBe('on_track');
  });

  it('does not touch existing checkpoints', () => {
    const first = addCheckpoint([], 'goal-1', 'user-1', 'first');
    const both = addCheckpoint(first, 'goal-1', 'user-1', 'second');

    expect(both).toHaveLength(2);
    expect(both[0].text).toBe('first');
    expect(both[1].text).toBe('second');
  });
});
