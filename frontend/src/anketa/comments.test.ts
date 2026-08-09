import { describe, expect, it } from 'vitest';
import { addComment, mergeComments, type Comment } from './comments';

function makeComment(id: string, createdAt: string): Comment {
  return { id, targetId: 'moodNow', authorId: 'user-1', text: `comment ${id}`, createdAt };
}

describe('mergeComments', () => {
  it('unions two lists by id, sorted by creation time', () => {
    const a = makeComment('a', '2026-01-01T00:00:00.000Z');
    const b = makeComment('b', '2026-01-02T00:00:00.000Z');
    const c = makeComment('c', '2026-01-03T00:00:00.000Z');

    const merged = mergeComments([a, c], [b, c]);

    expect(merged.map((comment) => comment.id)).toEqual(['a', 'b', 'c']);
  });

  it('does not duplicate a comment present in both lists', () => {
    const a = makeComment('a', '2026-01-01T00:00:00.000Z');

    const merged = mergeComments([a], [a]);

    expect(merged).toHaveLength(1);
  });
});

describe('addComment', () => {
  it('appends a new comment with a fresh id', () => {
    const result = addComment([], 'moodNow', 'user-1', 'nice answer');

    expect(result).toHaveLength(1);
    expect(result[0].targetId).toBe('moodNow');
    expect(result[0].text).toBe('nice answer');
    expect(result[0].id).toBeTruthy();
  });
});
