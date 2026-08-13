import { describe, expect, it } from 'vitest';
import { extractTrendValues, sparklinePoints } from './moodWorkloadTrend';
import type { FieldOption } from './questions';

const MOOD_OPTIONS: FieldOption[] = [
  { value: 'bad', labelKey: 'x' },
  { value: 'neutral', labelKey: 'x' },
  { value: 'good', labelKey: 'x' },
];

describe('extractTrendValues', () => {
  it('maps known option values to their index', () => {
    expect(
      extractTrendValues(
        [{ value: 'bad' }, { value: 'neutral' }, { value: 'good' }],
        MOOD_OPTIONS,
      ),
    ).toEqual([0, 1, 2]);
  });

  it('skips missing or unrecognized values rather than zero-filling', () => {
    expect(
      extractTrendValues(
        [
          { value: 'good' },
          { value: undefined },
          { value: 'unknown' },
          { value: 'bad' },
        ],
        MOOD_OPTIONS,
      ),
    ).toEqual([2, 0]);
  });

  it('returns an empty array for no rows', () => {
    expect(extractTrendValues([], MOOD_OPTIONS)).toEqual([]);
  });
});

describe('sparklinePoints', () => {
  it('returns an empty string for no values', () => {
    expect(sparklinePoints([], 2, 60, 20)).toBe('');
  });

  it('places a single value at x=0', () => {
    expect(sparklinePoints([1], 2, 60, 20)).toBe('0,10');
  });

  it('spans the full width for two points, and inverts y (higher index = lower y)', () => {
    expect(sparklinePoints([0, 2], 2, 60, 20)).toBe('0,20 60,0');
  });

  it('evenly spaces more than two points across the width', () => {
    const points = sparklinePoints([0, 1, 2, 1, 0], 2, 60, 20);
    const xs = points.split(' ').map((p) => Number(p.split(',')[0]));
    expect(xs).toEqual([0, 15, 30, 45, 60]);
  });

  it('keeps every y within [0, height]', () => {
    const points = sparklinePoints([0, 1, 2], 2, 60, 20);
    for (const pair of points.split(' ')) {
      const y = Number(pair.split(',')[1]);
      expect(y).toBeGreaterThanOrEqual(0);
      expect(y).toBeLessThanOrEqual(20);
    }
  });
});
