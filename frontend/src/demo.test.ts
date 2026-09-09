import { describe, expect, it } from 'vitest';
import { demoEmailFor } from './demo';

describe('demoEmailFor', () => {
  it('returns the matching demo email for a locale with translated demo content', () => {
    expect(demoEmailFor('ru')).toBe('demo-employee-ru@example.com');
  });

  it('returns the English demo email for English itself', () => {
    expect(demoEmailFor('en')).toBe('demo-employee@example.com');
  });

  it('falls back to the English demo email for a supported locale with no demo content yet', () => {
    expect(demoEmailFor('de')).toBe('demo-employee@example.com');
    expect(demoEmailFor('fr')).toBe('demo-employee@example.com');
  });

  it('falls back to the English demo email for an unsupported locale', () => {
    expect(demoEmailFor('it')).toBe('demo-employee@example.com');
  });

  it('falls back to the English demo email for an inherited Object.prototype key', () => {
    expect(demoEmailFor('constructor')).toBe('demo-employee@example.com');
    expect(demoEmailFor('toString')).toBe('demo-employee@example.com');
    expect(demoEmailFor('hasOwnProperty')).toBe('demo-employee@example.com');
  });
});
