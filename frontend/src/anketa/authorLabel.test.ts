import { describe, expect, it } from 'vitest';
import { resolveAuthorLabel } from './authorLabel';

describe('resolveAuthorLabel', () => {
  it('returns youLabel for the current viewer own id', () => {
    const result = resolveAuthorLabel(
      'user-1',
      'user-1',
      { 'user-1': 'Alex', 'user-2': 'Sam' },
      'You',
    );

    expect(result).toBe('You');
  });

  it("returns the counterpart's short name for any other known id", () => {
    const result = resolveAuthorLabel(
      'user-2',
      'user-1',
      { 'user-1': 'Alex', 'user-2': 'Sam' },
      'You',
    );

    expect(result).toBe('Sam');
  });

  it('falls back to the raw id when it is not the viewer and not in authorNames', () => {
    const result = resolveAuthorLabel('user-3', 'user-1', {}, 'You');

    expect(result).toBe('user-3');
  });
});
