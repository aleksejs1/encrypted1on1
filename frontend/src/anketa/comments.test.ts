import { describe, expect, it } from 'vitest';
import { addComment } from './comments';

describe('addComment', () => {
  it('appends a new comment with a fresh id', () => {
    const result = addComment([], 'moodNow', 'user-1', 'nice answer');

    expect(result).toHaveLength(1);
    expect(result[0].targetId).toBe('moodNow');
    expect(result[0].text).toBe('nice answer');
    expect(result[0].id).toBeTruthy();
  });
});
