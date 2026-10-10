import { describe, expect, it } from 'vitest';
import {
  ADMIN_TEMPLATE_PATTERN,
  adminTemplatePath,
  isKnownPath,
  MIGRATED_AUTHED_PATHS,
  PAIR_PATTERN,
  pairPath,
  PATHS,
} from './routes';

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
    expect(isKnownPath('/admin/templates/new')).toBe(true);
    expect(isKnownPath('/admin/templates/abc123')).toBe(true);
    expect(isKnownPath('/pair/abc123/def456')).toBe(true);
  });

  it('rejects unrecognized paths', () => {
    expect(isKnownPath('/not-a-real-page')).toBe(false);
    expect(isKnownPath('/anketas')).toBe(false);
    expect(isKnownPath('/anketas/')).toBe(false);
    expect(isKnownPath('/anketas/abc/def')).toBe(false);
    expect(isKnownPath('/admin/templates/')).toBe(false);
    expect(isKnownPath('/admin/templates/abc/def')).toBe(false);
    expect(isKnownPath('/pair')).toBe(false);
    expect(isKnownPath('/pair/abc')).toBe(false);
    expect(isKnownPath('/pair/abc/')).toBe(false);
    expect(isKnownPath('/pair/abc/def/ghi')).toBe(false);
    expect(isKnownPath('/activate')).toBe(false);
    expect(isKnownPath('/activate/')).toBe(false);
    expect(isKnownPath('')).toBe(false);
  });
});

describe('adminTemplatePath', () => {
  it('builds a path the template pattern matches, back to the same id', () => {
    const path = adminTemplatePath('0192-abc');
    expect(path).toBe('/admin/templates/0192-abc');
    expect(ADMIN_TEMPLATE_PATTERN.exec(path)?.[1]).toBe('0192-abc');
  });
});

describe('pairPath', () => {
  it('builds a path the pair pattern matches, back to both ids', () => {
    const path = pairPath('0192-abc', '0193-def');
    expect(path).toBe('/pair/0192-abc/0193-def');
    expect(PAIR_PATTERN.exec(path)?.slice(1)).toEqual(['0192-abc', '0193-def']);
  });

  it('is the same path whichever of the two builds it', () => {
    expect(pairPath('0193-def', '0192-abc')).toBe(
      pairPath('0192-abc', '0193-def'),
    );
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
    expect(MIGRATED_AUTHED_PATHS).not.toContain(PATHS.templatePreview);
  });
});
