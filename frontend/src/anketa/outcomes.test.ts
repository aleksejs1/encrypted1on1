import { describe, expect, it } from 'vitest';
import { addOutcome, toggleDone } from './outcomes';

describe('addOutcome', () => {
  it('appends a new, not-done item', () => {
    const result = addOutcome([], 'user-1', 'follow up on budget');

    expect(result).toHaveLength(1);
    expect(result[0].done).toBe(false);
    expect(result[0].authorId).toBe('user-1');
    expect(result[0].text).toBe('follow up on budget');
  });
});

describe('toggleDone', () => {
  it('flips done for the matching item only', () => {
    const items = addOutcome(
      addOutcome([], 'user-1', 'first'),
      'user-1',
      'second',
    );
    const toggled = toggleDone(items, items[0].id);

    expect(toggled[0].done).toBe(true);
    expect(toggled[1].done).toBe(false);
  });
});
