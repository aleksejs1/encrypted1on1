import { describe, expect, it } from 'vitest';
import { resolveArgon2Profile } from './argon2Profile';

describe('resolveArgon2Profile', () => {
  it('passes through each known profile name', () => {
    expect(resolveArgon2Profile('interactive')).toBe('interactive');
    expect(resolveArgon2Profile('moderate')).toBe('moderate');
    expect(resolveArgon2Profile('sensitive')).toBe('sensitive');
  });

  it('falls back to interactive when unset', () => {
    expect(resolveArgon2Profile(undefined)).toBe('interactive');
  });

  it('falls back to interactive on an empty or unrecognized value', () => {
    expect(resolveArgon2Profile('')).toBe('interactive');
    expect(resolveArgon2Profile('extreme')).toBe('interactive');
    expect(resolveArgon2Profile('Interactive')).toBe('interactive');
  });
});
