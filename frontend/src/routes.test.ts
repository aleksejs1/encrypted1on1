import { describe, expect, it } from 'vitest';
import { isKnownPath, MIGRATED_AUTHED_PATHS, PATHS } from './routes';

describe('isKnownPath', () => {
  it('accepts every static path', () => {
    for (const path of Object.values(PATHS)) {
      expect(isKnownPath(path)).toBe(true);
    }
  });

  it('accepts activation, reset-password, and anketa paths', () => {
    expect(isKnownPath('/activate/some-token')).toBe(true);
    expect(isKnownPath('/reset-password/some-token')).toBe(true);
    expect(isKnownPath('/anketas/abc123')).toBe(true);
    expect(isKnownPath('/anketas/new')).toBe(true);
  });

  it('rejects unrecognized paths', () => {
    expect(isKnownPath('/not-a-real-page')).toBe(false);
    expect(isKnownPath('/anketas')).toBe(false);
    expect(isKnownPath('/anketas/')).toBe(false);
    expect(isKnownPath('/anketas/abc/def')).toBe(false);
    expect(isKnownPath('/activate')).toBe(false);
    expect(isKnownPath('/activate/')).toBe(false);
    expect(isKnownPath('')).toBe(false);
  });
});

describe('MIGRATED_AUTHED_PATHS', () => {
  it('only contains actual PATHS values', () => {
    const staticPaths: string[] = Object.values(PATHS);
    for (const path of MIGRATED_AUTHED_PATHS) {
      expect(staticPaths).toContain(path);
    }
  });

  it('excludes the unauthenticated-only static paths', () => {
    expect(MIGRATED_AUTHED_PATHS).not.toContain(PATHS.forgotPassword);
    expect(MIGRATED_AUTHED_PATHS).not.toContain(PATHS.signup);
    expect(MIGRATED_AUTHED_PATHS).not.toContain(PATHS.createCompany);
  });
});
