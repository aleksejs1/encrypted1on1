import { describe, expect, it } from 'vitest';
import { addComment, deleteComment, editComment } from './comments';

describe('addComment', () => {
  it('appends a new comment with a fresh id', () => {
    const result = addComment([], 'moodNow', 'user-1', 'nice answer');

    expect(result).toHaveLength(1);
    expect(result[0].targetId).toBe('moodNow');
    expect(result[0].text).toBe('nice answer');
    expect(result[0].id).toBeTruthy();
  });
});

describe('editComment', () => {
  it('replaces the text of the matching comment by the same author', () => {
    const existing = addComment([], 'moodNow', 'user-1', 'original');
    const result = editComment(existing, existing[0].id, 'user-1', 'edited');

    expect(result).toHaveLength(1);
    expect(result[0].text).toBe('edited');
    expect(result[0].id).toBe(existing[0].id);
  });

  it('throws when the author does not match', () => {
    const existing = addComment([], 'moodNow', 'user-1', 'original');

    expect(() =>
      editComment(existing, existing[0].id, 'user-2', 'edited'),
    ).toThrow();
  });

  it('throws when the comment id does not match', () => {
    const existing = addComment([], 'moodNow', 'user-1', 'original');

    expect(() =>
      editComment(existing, 'missing-id', 'user-1', 'edited'),
    ).toThrow();
  });
});

describe('deleteComment', () => {
  it('removes the matching comment by the same author', () => {
    const existing = addComment([], 'moodNow', 'user-1', 'original');
    const result = deleteComment(existing, existing[0].id, 'user-1');

    expect(result).toHaveLength(0);
  });

  it('throws when the author does not match', () => {
    const existing = addComment([], 'moodNow', 'user-1', 'original');

    expect(() => deleteComment(existing, existing[0].id, 'user-2')).toThrow();
  });

  it('throws when the comment id does not match', () => {
    const existing = addComment([], 'moodNow', 'user-1', 'original');

    expect(() => deleteComment(existing, 'missing-id', 'user-1')).toThrow();
  });
});
