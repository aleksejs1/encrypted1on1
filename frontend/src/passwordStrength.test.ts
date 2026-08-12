import { describe, expect, it } from 'vitest';
import { scoreOf } from './passwordStrength';

describe('scoreOf', () => {
  it('scores an empty password as 0', () => {
    expect(scoreOf('')).toBe(0);
  });

  it('scores a short, single-case, letters-only password low', () => {
    expect(scoreOf('abc')).toBe(0);
  });

  it('scores a long password mixing case and digits/symbols at the max', () => {
    expect(scoreOf('Correct-Horse-Battery-9')).toBe(4);
  });

  it('never exceeds the max score of 4', () => {
    expect(scoreOf('Aa1!Aa1!Aa1!Aa1!Aa1!')).toBe(4);
  });
});
