import { describe, expect, it } from 'vitest';
import {
  firstWord,
  fullDisplayName,
  nameWithEmail,
  shortDisplayName,
} from './userDisplay';

describe('firstWord', () => {
  it('returns the first token of a multi-word name', () => {
    expect(firstWord('Alex Morgan')).toBe('Alex');
  });

  it('returns the whole string for a single-word name', () => {
    expect(firstWord('Alex')).toBe('Alex');
  });

  it('collapses surrounding and repeated whitespace', () => {
    expect(firstWord('  Alex   Morgan  ')).toBe('Alex');
  });

  it('returns an empty string for an empty name', () => {
    expect(firstWord('')).toBe('');
  });
});

describe('shortDisplayName', () => {
  it('returns just the first name when a name is set', () => {
    expect(shortDisplayName('Alex Morgan', 'alex@example.com')).toBe('Alex');
  });

  it('falls back to the full email when no name is set', () => {
    expect(shortDisplayName('', 'alex@example.com')).toBe('alex@example.com');
  });

  it('falls back to the full email when the name is only whitespace', () => {
    expect(shortDisplayName('   ', 'alex@example.com')).toBe(
      'alex@example.com',
    );
  });
});

describe('fullDisplayName', () => {
  it('returns the full name when set', () => {
    expect(fullDisplayName('Alex Morgan', 'alex@example.com')).toBe(
      'Alex Morgan',
    );
  });

  it('falls back to the email when no name is set', () => {
    expect(fullDisplayName('', 'alex@example.com')).toBe('alex@example.com');
  });

  it('trims surrounding whitespace from the name', () => {
    expect(fullDisplayName('  Alex Morgan  ', 'alex@example.com')).toBe(
      'Alex Morgan',
    );
  });
});

describe('nameWithEmail', () => {
  it('combines name and email when a name is set', () => {
    expect(nameWithEmail('Alex Morgan', 'alex@example.com')).toBe(
      'Alex Morgan (alex@example.com)',
    );
  });

  it('returns just the email when no name is set', () => {
    expect(nameWithEmail('', 'alex@example.com')).toBe('alex@example.com');
  });
});
