import { describe, expect, it } from 'vitest';
import { addOutcome, deleteOutcome, editOutcome, toggleDone } from './outcomes';

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

describe('editOutcome', () => {
  it('replaces the text of the matching item by the same author', () => {
    const existing = addOutcome([], 'user-1', 'original');
    const result = editOutcome(existing, existing[0].id, 'user-1', 'edited');

    expect(result).toHaveLength(1);
    expect(result[0].text).toBe('edited');
    expect(result[0].id).toBe(existing[0].id);
  });

  it('leaves other items untouched', () => {
    const items = addOutcome(
      addOutcome([], 'user-1', 'first'),
      'user-1',
      'second',
    );
    const result = editOutcome(items, items[0].id, 'user-1', 'edited');

    expect(result[0].text).toBe('edited');
    expect(result[1].text).toBe('second');
  });

  it('throws when the author does not match', () => {
    const existing = addOutcome([], 'user-1', 'original');

    expect(() =>
      editOutcome(existing, existing[0].id, 'user-2', 'edited'),
    ).toThrow();
  });

  it('throws when the item id does not match', () => {
    const existing = addOutcome([], 'user-1', 'original');

    expect(() =>
      editOutcome(existing, 'missing-id', 'user-1', 'edited'),
    ).toThrow();
  });
});

describe('deleteOutcome', () => {
  it('removes the matching item by the same author', () => {
    const existing = addOutcome([], 'user-1', 'original');
    const result = deleteOutcome(existing, existing[0].id, 'user-1');

    expect(result).toHaveLength(0);
  });

  it('leaves other items untouched', () => {
    const items = addOutcome(
      addOutcome([], 'user-1', 'first'),
      'user-1',
      'second',
    );
    const result = deleteOutcome(items, items[0].id, 'user-1');

    expect(result).toHaveLength(1);
    expect(result[0].text).toBe('second');
  });

  it('throws when the author does not match', () => {
    const existing = addOutcome([], 'user-1', 'original');

    expect(() => deleteOutcome(existing, existing[0].id, 'user-2')).toThrow();
  });

  it('throws when the item id does not match', () => {
    const existing = addOutcome([], 'user-1', 'original');

    expect(() => deleteOutcome(existing, 'missing-id', 'user-1')).toThrow();
  });
});
